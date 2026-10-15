<?php

namespace App\Services\Fleet;

use App\Repositories\HistoricalMissingHandoffRepairRepository;
use App\Repositories\MovementChecklistRepository;
use App\Repositories\OperationalFactsRepository;
use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;

/** Trusted CLI-only correction of one missing start in an already closed interval. */
final class HistoricalMissingHandoffRepairService
{
    public const OPERATION = 'historical_missing_handoff.v1';
    private readonly HistoricalMissingHandoffRepairRepository $repository;
    private readonly OperationalFactsRepository $facts;
    private readonly HistoricalMissingHandoffProjection $projection;

    public function __construct(private readonly BaseConnection $db, ?HistoricalMissingHandoffRepairRepository $repository = null)
    {
        $this->repository = $repository ?? new HistoricalMissingHandoffRepairRepository($db);
        if ($this->repository->db !== $db) {
            throw new InvalidArgumentException('Historical handoff repair requires one connection.');
        }
        $this->facts = new OperationalFactsRepository($db);
        $this->projection = new HistoricalMissingHandoffProjection($db);
    }

    public function preview(array $request, int $actor): array
    {
        return $this->execute($request, $actor, null);
    }

    public function apply(array $request, int $actor, string $confirmation): array
    {
        if (! preg_match('/^[a-f0-9]{64}$/D', $confirmation)) {
            throw new InvalidArgumentException('Confirm the exact historical_missing_handoff.v1 preview hash.');
        }
        return $this->execute($request, $actor, $confirmation);
    }

    private function execute(array $request, int $actor, ?string $confirmation): array
    {
        $r = $this->normalize($request);
        if ($this->db->transDepth !== 0 || ! in_array($this->db->DBDriver, ['MySQLi', 'SQLite3'], true)) {
            throw new RuntimeException('Historical handoff repair must own its root transaction.');
        }
        if ($this->db->DBDriver === 'MySQLi') {
            $this->db->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            if ($confirmation === null) {
                $this->db->query('SET TRANSACTION READ ONLY');
            }
        }
        if (! $this->db->transBegin()) {
            throw new RuntimeException('Historical handoff transaction could not begin.');
        }
        try {
            $this->repository->requireTransactionalTables();
            if ($confirmation !== null) {
                // Current locking reads precede every consistent data read. A waiter
                // must not retain a snapshot from before the winning writer committed.
                $this->repository->lockReceipts();
                $this->repository->lockInputs($r['company_id'], $r['vehicle_id']);
            }
            $this->repository->authorize($actor, $confirmation !== null);
            $receipt = $this->repository->commandReceipt($r['operation_uuid']);
            if ($receipt !== null) {
                $result = $this->replay($r, $actor, $confirmation, $receipt);
            } else {
                $now = new DateTimeImmutable('now', new DateTimeZone((new \Config\App())->appTimezone));
                $owned = $this->ownership($r);
                $state = $this->repository->state($r['vehicle_id']);
                $before = $this->projection->capture($r, $state, $now);
                try {
                    $boundaries = $this->validate($r, $owned, $state, $before, $now);
                } catch (RuntimeException $e) {
                    if ($confirmation !== null) {
                        throw new RuntimeException('Historical handoff preview is stale or unsafe: ' . $e->getMessage(), 0, $e);
                    }
                    throw $e;
                }
                $hash = $this->hash([self::OPERATION, $r, $actor, $owned, $state, $before, $boundaries]);
                $result = ['operation' => self::OPERATION, 'status' => 'preview', 'operation_uuid' => $r['operation_uuid'],
                    'confirmation' => $hash, 'scope' => array_intersect_key($r, array_flip(['company_id', 'vehicle_id', 'trip_id'])),
                    'creates' => ['actual_handoff' => $r['handoff']], 'assessment_creation' => false,
                    'boundaries' => $boundaries, 'schedule' => $owned['trip'], 'checklists' => $owned['checklists'],
                    'pickup_task_discrepancy' => array_values(array_filter($state['trip_movement_checklist_items'], static fn ($i) => (int) $i['trip_movement_checklist_id'] === $r['pickup_checklist_id'] && $i['item_code'] === 'guest_handoff_completed')),
                    'before' => $before['projected'], 'projected_after' => $boundaries['preservation']['historical_after'], 'conflicts' => [],
                    'historical_affordances_retired' => $boundaries['preservation']['retired'],
                    'current_future_before' => $boundaries['preservation']['current_before'], 'current_future_after' => $boundaries['preservation']['current_after'],
                    'preservation_fingerprints' => ['state' => $this->hash($state), 'inputs' => $this->hash($before['inputs']), 'authority' => $this->hash($before['projected'])]];
                if ($confirmation !== null) {
                    if (! hash_equals($hash, $confirmation)) {
                        throw new RuntimeException('Historical handoff preview is stale; read and explicitly confirm a fresh preview.');
                    }
                    $result = $this->mutate($r, $actor, $hash, $state, $before, $boundaries);
                }
            }
            if (! $this->db->transStatus() || ! $this->db->transCommit()) {
                throw new RuntimeException('Historical handoff acknowledgement failed; inspect/replay the operation UUID before assuming success.');
            }
            return $result;
        } catch (\Throwable $e) {
            $this->db->transRollback();
            throw $e;
        }
    }

    private function normalize(array $r): array
    {
        $keys = ['operation_uuid', 'company_id', 'vehicle_id', 'trip_id', 'pickup_checklist_id', 'return_checklist_id', 'recovery_event_id', 'recovery_assessment_id', 'handoff', 'reason', 'attestation'];
        if (array_diff(array_keys($r), $keys) !== []) {
            throw new InvalidArgumentException('Unsupported historical handoff fields, including measurements or actor, are rejected.');
        }
        if (! is_string($r['operation_uuid'] ?? null) || ! preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $r['operation_uuid'])) {
            throw new InvalidArgumentException('An explicit canonical v4 operation UUID is required.');
        }
        $n = ['operation_uuid' => $r['operation_uuid']];
        foreach (array_slice($keys, 1, 6) as $key) {
            if (! is_int($r[$key] ?? null) || $r[$key] < 1) {
                throw new InvalidArgumentException('Explicit positive integer scope and boundary identifiers are required.');
            }
            $n[$key] = $r[$key];
        }
        if (! array_key_exists('recovery_assessment_id', $r) || ($r['recovery_assessment_id'] !== null && (! is_int($r['recovery_assessment_id']) || $r['recovery_assessment_id'] < 1))) {
            throw new InvalidArgumentException('Identify the preserved recovery assessment or explicit null.');
        }
        $n['recovery_assessment_id'] = $r['recovery_assessment_id'];
        $h = $r['handoff'] ?? null;
        if (! is_array($h) || array_diff(array_keys($h), ['occurred_at', 'location_class', 'location_detail', 'airport_garage_code', 'airport_parking_level', 'airport_parking_row', 'location_note']) !== []) {
            throw new InvalidArgumentException('Supply only historical time and attested handoff location.');
        }
        $time = $h['occurred_at'] ?? null;
        $parsed = is_string($time) ? DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $time, new DateTimeZone((new \Config\App())->appTimezone)) : false;
        if (! $parsed || $parsed->format('Y-m-d H:i:s') !== $time) {
            throw new InvalidArgumentException('Use a valid local Y-m-d H:i:s historical occurrence.');
        }
        if (! is_string($h['location_class'] ?? null) || ! in_array($h['location_class'], ['home', 'airport_hnl', 'waikiki_hotel', 'other_delivery'], true)) {
            throw new InvalidArgumentException('An attested known location is required.');
        }
        $detail = ($h['location_detail'] ?? null) === null ? null : $this->text($h['location_detail'], 500);
        (new LocationClassificationService())->explicit($h['location_class'], $detail);
        $parking = ['garage_code' => null, 'level' => null, 'row' => null];
        if (($h['airport_garage_code'] ?? null) !== null || ($h['airport_parking_level'] ?? null) !== null || ($h['airport_parking_row'] ?? null) !== null) {
            if ($h['location_class'] !== 'airport_hnl' || ! is_string($h['airport_garage_code'] ?? null) || (new HnlGarageCatalog())->definition($h['airport_garage_code']) === null || ! is_string($h['airport_parking_row'] ?? null)) {
                throw new InvalidArgumentException('Structured HNL parking requires matching garage, level and row.');
            }
            $parking = (new HnlGarageCatalog())->validate($h['airport_garage_code'], $h['airport_parking_level'] ?? null, $h['airport_parking_row']);
            $canonicalDetail = (new HnlGarageCatalog())->locationDetail($parking);
            if ($detail !== null && $detail !== $canonicalDetail) {
                throw new InvalidArgumentException('Location detail conflicts with structured parking; use location_note for an additional attested note.');
            }
            $detail = $canonicalDetail;
        }
        $n['handoff'] = ['occurred_at' => $time, 'location_class' => $h['location_class'], 'location_detail' => $detail,
            'airport_garage_code' => $parking['garage_code'], 'airport_parking_level' => $parking['level'], 'airport_parking_row' => $parking['row'],
            'location_note' => ($h['location_note'] ?? null) === null ? null : $this->text($h['location_note'], 500)];
        $n['reason'] = $this->text($r['reason'] ?? null, 2000);
        $n['attestation'] = $this->text($r['attestation'] ?? null, 2000);
        return $n;
    }

    private function text(mixed $value, int $limit): string
    {
        if (! is_string($value) || trim($value) === '' || mb_strlen($value) > $limit || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value)) {
            throw new InvalidArgumentException('An explicit nonempty bounded reason, attestation or location text is required.');
        }
        return trim($value);
    }

    private function ownership(array $r): array
    {
        $vehicle = $this->facts->vehicleForCompany($r['company_id'], $r['vehicle_id']);
        $trip = $this->facts->tripSchedule($r['trip_id']);
        if ($vehicle === null || $trip === null || (int) $trip['fleet_vehicle_id'] !== $r['vehicle_id'] || $trip['deleted_at'] !== null || $trip['canceled_at'] !== null || ! in_array($trip['trip_status_code'], ['completed', 'in_progress'], true)) {
            throw new RuntimeException('Historical handoff requires an owned active vehicle and noncanceled historically closed trip.');
        }
        $checklists = [];
        foreach (['pickup', 'return'] as $type) {
            $c = (new MovementChecklistRepository($this->db))->checklistForCompany($r['company_id'], $r[$type . '_checklist_id']);
            if ($c === null || (int) $c['fleet_vehicle_id'] !== $r['vehicle_id'] || (int) $c['turo_trip_normalized_id'] !== $r['trip_id'] || $c['movement_type'] !== $type) {
                throw new RuntimeException('Historical handoff checklist ownership mismatch.');
            }
            $checklists[$type] = $c;
        }
        return ['vehicle' => $vehicle, 'trip' => $trip, 'checklists' => $checklists];
    }

    private function validate(array $r, array $owned, array $state, array $before, DateTimeImmutable $now): array
    {
        $at = $r['handoff']['occurred_at'];
        $events = $state['trip_movement_events'];
        $target = array_values(array_filter($events, static fn ($e) => (int) $e['turo_trip_normalized_id'] === $r['trip_id']));
        foreach ($target as $e) {
            if ((int) $e['company_id'] !== $r['company_id'] || $e['event_code'] === 'actual_handoff') {
                throw new RuntimeException('Existing handoff or conflicting trip-event ownership requires separate review.');
            }
        }
        $active = array_values(array_filter($target, static fn ($e) => $e['voided_at'] === null));
        $closing = array_values(array_filter($active, static fn ($e) => in_array($e['event_code'], ['actual_return', 'vehicle_recovered'], true)));
        if (count($closing) !== 1 || $closing[0]['event_code'] !== 'vehicle_recovered' || (int) $closing[0]['id'] !== $r['recovery_event_id'] || $closing[0]['supersedes_event_id'] !== null) {
            throw new RuntimeException('One unambiguous active original same-trip recovery is required.');
        }
        $recovery = $closing[0];
        if ($recovery['occurred_at'] <= $at || $recovery['occurred_at'] >= $now->format('Y-m-d H:i:s') || $recovery['movement_type'] !== 'return') {
            throw new RuntimeException('Handoff must strictly precede an existing past same-trip recovery.');
        }
        $window = (new \Config\MovementIntelligence())->repairCandidateWindowHours * 3600;
        if (abs((new DateTimeImmutable($at))->getTimestamp() - (new DateTimeImmutable($owned['trip']['starts_at']))->getTimestamp()) > $window) {
            throw new RuntimeException('Handoff is outside the historical trip window.');
        }
        $linked = [];
        foreach ($state['movement_assessments'] as $a) {
            if ((int) $a['turo_trip_normalized_id'] === $r['trip_id'] && $a['movement_type'] === 'pickup' && $a['voided_at'] === null) {
                throw new RuntimeException('An existing pickup assessment requires separate review.');
            }
            if ((int) $a['trip_movement_event_id'] === $r['recovery_event_id'] && $a['voided_at'] === null) {
                if ((int) $a['company_id'] !== $r['company_id'] || (int) $a['turo_trip_normalized_id'] !== $r['trip_id'] || $a['movement_type'] !== 'return' || $a['captured_at'] !== $recovery['occurred_at']) {
                    throw new RuntimeException('Recovery assessment ownership or chronology mismatch.');
                }
                $linked[] = $a;
            }
        }
        if (count($linked) > 1 || (isset($linked[0]) ? (int) $linked[0]['id'] : null) !== $r['recovery_assessment_id']) {
            throw new RuntimeException('Preserved recovery assessment is missing or ambiguous.');
        }
        $reports = array_values(array_filter($active, static fn ($e) => $e['event_code'] === 'guest_return_staged'));
        if (count($reports) > 1) {
            throw new RuntimeException('Ambiguous guest-return reports require separate review.');
        }
        $allowed = array_merge([$r['recovery_event_id']], array_map('intval', array_column($reports, 'id')));
        foreach ($events as $e) {
            if ($e['voided_at'] !== null) {
                continue;
            }
            if ($e['occurred_at'] === $at || ($e['occurred_at'] > $at && $e['occurred_at'] <= $recovery['occurred_at'] && ! in_array((int) $e['id'], $allowed, true))) {
                throw new RuntimeException('Overlapping movement or ambiguous same-second handoff chronology.');
            }
        }
        foreach ($reports as $report) {
            if ($report['occurred_at'] <= $at || $report['occurred_at'] >= $recovery['occurred_at'] || $report['movement_type'] !== 'return') {
                throw new RuntimeException('Guest-return report must lie strictly inside the closed interval.');
            }
        }
        $custody = new CurrentVehicleCustodyService($this->facts);
        $timeline = $before['inputs']['timeline'];
        $prior = $custody->resolveEvents(array_values(array_filter($timeline, static fn ($e) => $e['occurred_at'] < $at)));
        if ($prior['custody'] !== 'operator' || $prior['integrity_conflict'] || $prior['occurred_at'] === null || $prior['occurred_at'] >= $at || $before['projected']['custody']['integrity_conflict']) {
            throw new RuntimeException('A conflict-free prior operator custody boundary is required.');
        }
        $event = $r['handoff'] + ['id' => -1, 'company_id' => $r['company_id'], 'fleet_vehicle_id' => $r['vehicle_id'],
            'turo_trip_normalized_id' => $r['trip_id'], 'event_code' => 'actual_handoff', 'custody_trip_starts_at' => $owned['trip']['starts_at'],
            'custody_trip_ends_at' => $owned['trip']['ends_at'], 'custody_trip_status_code' => $owned['trip']['trip_status_code'], 'custody_trip_canceled_at' => null];
        $timeline[] = $event;
        $closed = $custody->resolveEvents(array_values(array_filter($timeline, static fn ($e) => $e['occurred_at'] <= $recovery['occurred_at'])));
        $hyp = $this->projection->withHandoff($r, $before['inputs'], $event);
        $historical = $this->projection->historicalView($r, $hyp, $before['inputs']['commercial'], $now);
        $currentBefore = $this->projection->currentFuture($r, $before['inputs'], $now);
        $currentAfter = $this->projection->currentFuture($r, $hyp, $now);
        $retired = (new HistoricalMissingHandoffPreservation())->compare($r, $before['inputs'], $before['projected'], $historical, $currentBefore, $currentAfter);
        if ($closed['custody'] !== 'operator' || $closed['basis_event_id'] !== $r['recovery_event_id'] || $closed['integrity_conflict']) {
            throw new RuntimeException('Hypothetical handoff fails interval closure.');
        }
        $preservation = ['retired' => $retired, 'historical_after' => $historical, 'current_before' => $currentBefore, 'current_after' => $currentAfter,
            'unfiltered_hypothetical_fingerprint' => $this->hash([$hyp, $this->projection->project($r, $hyp, $now)]),
            'classification_fingerprint' => $this->hash($retired)];
        return ['preservation' => $preservation, 'prior' => $prior['basis_event'], 'guest_return_staged' => $reports, 'recovery' => $recovery,
            'recovery_assessment' => $linked[0] ?? null, 'later_events' => array_values(array_filter($events, static fn ($e) => $e['occurred_at'] > $recovery['occurred_at']))];
    }

    private function mutate(array $r, int $actor, string $confirmation, array $state, array $before, array $boundaries): array
    {
        $h = $r['handoff'];
        $id = (new MovementEventService($this->facts))->record(
            $r['vehicle_id'],
            $r['trip_id'],
            'actual_handoff',
            'pickup',
            $h['occurred_at'],
            $h['location_class'],
            $h['location_detail'],
            self::OPERATION,
            $actor,
            $h['location_note'],
            ['garage_code' => $h['airport_garage_code'], 'level' => $h['airport_parking_level'], 'row' => $h['airport_parking_row']]
        );
        if ($id < 1) {
            throw new RuntimeException('Missing historical handoff insert failed.');
        }
        $event = $this->facts->event($id);
        $auditData = ['operation' => self::OPERATION, 'operation_uuid' => $r['operation_uuid'], 'actor_user_id' => $actor,
            'company_id' => $r['company_id'], 'vehicle_id' => $r['vehicle_id'], 'trip_id' => $r['trip_id'], 'event_id' => $id,
            'occurred_at' => $h['occurred_at'], 'recorded_at' => $event['created_at'], 'reason' => $r['reason'], 'attestation' => $r['attestation'],
            'confirmation' => $confirmation, 'preserved_recovery_event_id' => $r['recovery_event_id'], 'preserved_recovery_assessment_id' => $r['recovery_assessment_id'],
            'historical_affordances_retired' => $boundaries['preservation']['retired'],
            'historical_classification_fingerprint' => $boundaries['preservation']['classification_fingerprint'],
            'current_future_authority_fingerprint' => $this->hash($boundaries['preservation']['current_before']),
            'preserved_authority_fingerprint' => $this->hash($before['projected']), 'boundary_fingerprint' => $this->hash($boundaries)];
        $this->repository->audit($r['company_id'], 'trip_movement_events', $id, 'historical_missing_handoff_created', $actor, [], $auditData);
        $creationAudit = (int) $this->db->insertID();
        $now = new DateTimeImmutable('now', new DateTimeZone((new \Config\App())->appTimezone));
        $afterState = $this->repository->state($r['vehicle_id']);
        $after = $this->projection->capture($r, $afterState, $now);
        $oldProjection = $this->projection->project($r, $before['inputs'], $now);
        if ($this->protectedState($afterState, $id, [$creationAudit]) !== $state) {
            throw new RuntimeException('Historical handoff preservation failed; entire operation rolled back.');
        }
        $historicalAfter = $this->projection->historicalView($r, $after['inputs'], $before['inputs']['commercial'], $now);
        $currentBefore = $this->projection->currentFuture($r, $before['inputs'], $now);
        $currentAfter = $this->projection->currentFuture($r, $after['inputs'], $now);
        $retired = (new HistoricalMissingHandoffPreservation())->compare($r, $before['inputs'], $oldProjection, $historicalAfter, $currentBefore, $currentAfter);
        if ($retired !== $boundaries['preservation']['retired']) {
            throw new RuntimeException('Historical handoff classification changed during apply; preservation failed.');
        }
        $result = ['operation' => self::OPERATION, 'status' => 'applied', 'operation_uuid' => $r['operation_uuid'], 'confirmation' => $confirmation,
            'event_id' => $id, 'creation_audit_id' => $creationAudit, 'before' => $oldProjection, 'after' => $historicalAfter, 'historical_affordances_retired' => $retired,
            'current_future_fingerprints' => ['before' => $this->hash($currentBefore), 'after' => $this->hash($currentAfter)], 'preservation' => true, 'assessment_created' => false];
        $receipt = $auditData + ['fingerprint' => $this->hash([$r, $actor]), 'event_hash' => $this->hash($event), 'creation_audit_hash' => $this->hash($this->repository->rows('operational_fact_audits', ['id' => $creationAudit], false, 1)[0]), 'result' => $result, 'result_hash' => $this->hash($result)];
        $this->repository->audit($r['company_id'], 'turo_trips_normalized', $r['trip_id'], HistoricalMissingHandoffRepairRepository::RECEIPT_ACTION, $actor, [], $receipt);
        $receiptId = (int) $this->db->insertID();
        $finalState = $this->repository->state($r['vehicle_id']);
        $final = $this->projection->capture($r, $finalState, new DateTimeImmutable('now', new DateTimeZone((new \Config\App())->appTimezone)));
        $stored = $this->repository->commandReceipt($r['operation_uuid']);
        if ($stored === null || $stored['receipt'] !== $receipt || (int) $stored['audit']['id'] !== $receiptId || $this->protectedState($finalState, $id, [$creationAudit, $receiptId]) !== $state || $final['projected'] !== $after['projected'] || $this->hash($this->facts->event($id)) !== $receipt['event_hash']) {
            throw new RuntimeException('Final historical handoff receipt/preservation check failed; entire operation rolled back.');
        }
        return $result + ['receipt_audit_id' => $receiptId];
    }

    private function protectedState(array $state, int $eventId, array $auditIds): array
    {
        $state['trip_movement_events'] = array_values(array_filter($state['trip_movement_events'], static fn ($e) => (int) $e['id'] !== $eventId));
        $state['operational_fact_audits'] = array_values(array_filter($state['operational_fact_audits'], static fn ($a) => ! in_array((int) $a['id'], $auditIds, true)));
        return $state;
    }

    private function receiptPreservationIntact(array $receipt): bool
    {
        $result = $receipt['result'];
        if (! array_key_exists('result_hash', $receipt)) {
            // Original v1 receipts predate the exception and required equality.
            return ($result['before'] ?? null) === ($result['after'] ?? null);
        }
        return is_string($receipt['result_hash']) && hash_equals($receipt['result_hash'], $this->hash($result))
            && is_string($result['current_future_fingerprints']['before'] ?? null)
            && $result['current_future_fingerprints']['before'] === ($result['current_future_fingerprints']['after'] ?? null)
            && is_array($result['historical_affordances_retired'] ?? null)
            && $result['historical_affordances_retired'] === ($receipt['historical_affordances_retired'] ?? null)
            && $this->hash($result['historical_affordances_retired']) === ($receipt['historical_classification_fingerprint'] ?? null)
            && $result['current_future_fingerprints']['before'] === ($receipt['current_future_authority_fingerprint'] ?? null);
    }

    private function replay(array $r, int $actor, ?string $confirmation, array $row): array
    {
        $receipt = $row['receipt'];
        if (($receipt['operation'] ?? null) !== self::OPERATION || ($receipt['fingerprint'] ?? null) !== $this->hash([$r, $actor]) || ($confirmation !== null && ($receipt['confirmation'] ?? null) !== $confirmation)) {
            throw new RuntimeException('Operation UUID was already used with a different payload, actor or preview.');
        }
        if (! is_array($receipt['result'] ?? null) || ($receipt['result']['operation'] ?? null) !== self::OPERATION || ($receipt['result']['status'] ?? null) !== 'applied' || ($receipt['result']['operation_uuid'] ?? null) !== $r['operation_uuid'] || ($receipt['result']['confirmation'] ?? null) !== ($receipt['confirmation'] ?? null) || ($receipt['result']['event_id'] ?? null) !== ($receipt['event_id'] ?? null) || ($receipt['result']['preservation'] ?? null) !== true || ($receipt['result']['assessment_created'] ?? null) !== false || ! $this->receiptPreservationIntact($receipt)) {
            throw new RuntimeException('Original historical handoff receipt result changed; separate review required.');
        }
        $event = $this->facts->event((int) ($receipt['event_id'] ?? 0));
        $audit = $this->repository->rows('operational_fact_audits', ['id' => $receipt['result']['creation_audit_id']], false, 1)[0] ?? null;
        if ($event === null || $event['voided_at'] !== null || $this->hash($event) !== ($receipt['event_hash'] ?? '') || $audit === null || $audit['action'] !== 'historical_missing_handoff_created' || $this->hash($audit) !== ($receipt['creation_audit_hash'] ?? '') || (int) $row['audit']['company_id'] !== $r['company_id'] || $row['audit']['table_name'] !== 'turo_trips_normalized' || (int) $row['audit']['record_id'] !== $r['trip_id'] || (int) $row['audit']['actor_user_id'] !== $actor) {
            throw new RuntimeException('Original historical handoff or audit changed; separate review required.');
        }
        return array_replace($receipt['result'], ['status' => 'already_applied', 'receipt_audit_id' => (int) $row['audit']['id'], 'preservation_at_apply' => true]);
    }

    private function hash(mixed $value): string
    {
        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR));
    }
}
