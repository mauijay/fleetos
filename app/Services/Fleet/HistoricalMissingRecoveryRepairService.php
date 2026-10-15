<?php

namespace App\Services\Fleet;

use App\Repositories\HistoricalMissingRecoveryRepairRepository;
use App\Repositories\MovementChecklistRepository;
use App\Repositories\OperationalFactsRepository;
use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;

/** Privileged CLI recovery of one already handed-off historical reservation. */
final class HistoricalMissingRecoveryRepairService
{
    public const OPERATION = 'historical_missing_recovery.v1';
    private readonly HistoricalMissingRecoveryRepairRepository $repository;
    private readonly OperationalFactsRepository $facts;
    private readonly HistoricalMissingRecoveryProjection $projection;

    public function __construct(private readonly BaseConnection $db, ?HistoricalMissingRecoveryRepairRepository $repository = null, ?HistoricalMissingRecoveryProjection $projection = null)
    {
        $this->repository = $repository ?? new HistoricalMissingRecoveryRepairRepository($db);
        if ($this->repository->db !== $db) {
            throw new InvalidArgumentException('Historical recovery requires one database connection.');
        }
        $this->facts = new OperationalFactsRepository($db);
        $this->projection = $projection ?? new HistoricalMissingRecoveryProjection($db);
    }

    public function preview(array $request, int $actor): array
    {
        return $this->execute($request, $actor, null);
    }

    public function apply(array $request, int $actor, string $confirmation): array
    {
        if (! preg_match('/^[a-f0-9]{64}$/D', $confirmation)) {
            throw new InvalidArgumentException('Confirm the exact historical_missing_recovery.v1 preview hash.');
        }
        return $this->execute($request, $actor, $confirmation);
    }

    private function execute(array $request, int $actor, ?string $confirmation): array
    {
        $r = $this->normalize($request);
        if ($this->db->transDepth !== 0 || ! in_array($this->db->DBDriver, ['MySQLi', 'SQLite3'], true)) {
            throw new RuntimeException('Historical recovery must own its root transaction.');
        }
        if ($this->db->DBDriver === 'MySQLi') {
            $this->db->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            if ($confirmation === null) {
                $this->db->query('SET TRANSACTION READ ONLY');
            }
        }
        if (! $this->db->transBegin()) {
            throw new RuntimeException('Historical recovery transaction could not begin.');
        }
        try {
            $this->repository->requireTransactionalTables();
            if ($confirmation !== null) {
                $this->repository->lockReceipts();
                $this->repository->lockInputs($r['company_id'], $r['vehicle_id']);
            }
            $this->repository->authorize($actor, $confirmation !== null);
            $receipt = $this->repository->commandReceipt($r['operation_uuid']);
            if ($receipt !== null) {
                $result = $this->replay($r, $actor, $confirmation, $receipt);
            } else {
                $now = new DateTimeImmutable('now', new DateTimeZone((new \Config\App())->appTimezone));
                try {
                    $owned = $this->ownership($r);
                    $state = $this->repository->state($r['vehicle_id']);
                    $firewall = $this->repository->firewall();
                    $before = $this->projection->capture($r, $state, $now);
                    $boundaries = $this->validate($r, $owned, $state, $before['inputs'], $now);
                    [$event, $assessment] = $this->virtualRows($r, $actor);
                    $inputs = $this->projection->withRecovery($r, $before['inputs'], $event, $assessment);
                    $after = $this->projection->views($r, $inputs, $now);
                    $beforeViews = $this->projection->normalize($before['views']);
                    $afterViews = $this->projection->normalize($after);
                    $changes = (new HistoricalMissingRecoveryPreservation())->compare($r, $before['inputs'], $beforeViews, $afterViews);
                } catch (RuntimeException $e) {
                    if ($confirmation !== null) {
                        throw new RuntimeException('Historical recovery preview is stale or unsafe: ' . $e->getMessage(), 0, $e);
                    }
                    throw $e;
                }
                $hash = $this->hash([self::OPERATION, $r, $actor, $owned, $state, $firewall, $before, $afterViews, $boundaries, $changes]);
                $result = ['operation' => self::OPERATION, 'status' => 'preview', 'operation_uuid' => $r['operation_uuid'], 'confirmation' => $hash,
                    'scope' => array_intersect_key($r, array_flip(['company_id', 'vehicle_id', 'trip_id'])),
                    'preserved_handoff' => $owned['handoff'], 'preserved_handoff_assessment' => $owned['assessment'],
                    'creates' => ['vehicle_recovered' => $r['recovery'], 'return_assessment' => $r['assessment']],
                    'cleanliness_mapping' => ['canonical' => $r['assessment']['cleanliness'], 'operator_note' => $r['assessment']['note']],
                    'later_events' => $boundaries['later_events'], 'checklists' => $owned['checklists'],
                    'before' => $beforeViews, 'after' => $afterViews, 'expected_changes' => $changes, 'unrelated_changes' => [],
                    'positioning_separate' => true, 'position_after_recovery' => $afterViews['authority']['position'],
                    'position_effect' => (int) ($afterViews['authority']['position']['event_id'] ?? 0) === -1
                        ? 'Current position becomes the recovery location until a later position is separately recorded.'
                        : 'Existing later position authority is preserved; this operation creates no position event.',
                    'preservation_fingerprints' => ['state' => $this->hash($state), 'firewall' => $this->hash($firewall), 'inputs' => $this->hash($before['inputs'])]];
                if ($confirmation !== null) {
                    if (! hash_equals($hash, $confirmation)) {
                        throw new RuntimeException('Historical recovery preview is stale; explicitly confirm a fresh preview.');
                    }
                    $result = $this->mutate($r, $actor, $hash, $owned, $state, $firewall, $before, $changes);
                }
            }
            if (! $this->db->transStatus() || ! $this->db->transCommit()) {
                throw new RuntimeException('Historical recovery acknowledgement failed; inspect/replay the existing operation UUID.');
            }
            return $result;
        } catch (\Throwable $e) {
            $this->db->transRollback();
            throw $e;
        }
    }

    private function normalize(array $r): array
    {
        $keys = ['operation_uuid', 'company_id', 'vehicle_id', 'trip_id', 'pickup_checklist_id', 'return_checklist_id', 'handoff_event_id', 'handoff_assessment_id', 'recovery', 'assessment', 'reason', 'attestation'];
        if (array_diff(array_keys($r), $keys) !== [] || ! is_string($r['operation_uuid'] ?? null) || ! preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $r['operation_uuid'])) {
            throw new InvalidArgumentException('Explicit canonical v4 UUID and only supported recovery fields are required.');
        }
        $n = ['operation_uuid' => $r['operation_uuid']];
        foreach (array_slice($keys, 1, 6) as $key) {
            if (! is_int($r[$key] ?? null) || $r[$key] < 1) {
                throw new InvalidArgumentException('Explicit positive integer ownership/checklist/handoff identifiers required.');
            }
            $n[$key] = $r[$key];
        }
        if (! array_key_exists('handoff_assessment_id', $r) || ($r['handoff_assessment_id'] !== null && (! is_int($r['handoff_assessment_id']) || $r['handoff_assessment_id'] < 1))) {
            throw new InvalidArgumentException('Identify the preserved handoff assessment or explicit null.');
        }
        $n['handoff_assessment_id'] = $r['handoff_assessment_id'];
        $h = $r['recovery'] ?? null;
        if (! is_array($h) || array_diff(array_keys($h), ['occurred_at', 'location_class', 'location_detail', 'airport_garage_code', 'airport_parking_level', 'airport_parking_row', 'location_note']) !== []) {
            throw new InvalidArgumentException('Supply only historical recovery occurrence and attested location.');
        }
        $time = $h['occurred_at'] ?? null;
        $parsed = is_string($time) ? DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $time, new DateTimeZone((new \Config\App())->appTimezone)) : false;
        if (! $parsed || $parsed->format('Y-m-d H:i:s') !== $time) {
            throw new InvalidArgumentException('Use a valid local Y-m-d H:i:s recovery occurrence.');
        }
        if (! is_string($h['location_class'] ?? null) || ! (new LocationClassificationService())->isRecoveryLocation($h['location_class'])) {
            throw new InvalidArgumentException('An attested known recovery location is required.');
        }
        $detail = ($h['location_detail'] ?? null) === null ? null : $this->text($h['location_detail'], 500);
        $parking = ['garage_code' => null, 'level' => null, 'row' => null];
        if (($h['airport_garage_code'] ?? null) !== null || ($h['airport_parking_level'] ?? null) !== null || ($h['airport_parking_row'] ?? null) !== null || $h['location_class'] === 'airport_hnl') {
            if ($h['location_class'] !== 'airport_hnl' || ! is_string($h['airport_garage_code'] ?? null) || ! is_string($h['airport_parking_row'] ?? null)) {
                throw new InvalidArgumentException('Structured HNL recovery garage, level and row required.');
            }
            $parking = (new HnlGarageCatalog())->validate($h['airport_garage_code'], $h['airport_parking_level'] ?? null, $h['airport_parking_row']);
            $canonical = (new HnlGarageCatalog())->locationDetail($parking);
            if ($detail !== null && $detail !== $canonical) {
                throw new InvalidArgumentException('Recovery detail conflicts with canonical parking.');
            }
            $detail = $canonical;
        }
        $n['recovery'] = ['occurred_at' => $time, 'location_class' => $h['location_class'], 'location_detail' => $detail,
            'airport_garage_code' => $parking['garage_code'], 'airport_parking_level' => $parking['level'], 'airport_parking_row' => $parking['row'],
            'location_note' => ($h['location_note'] ?? null) === null ? null : $this->text($h['location_note'], 500)];
        $a = $r['assessment'] ?? null;
        if (! is_array($a) || array_diff(array_keys($a), ['cleanliness', 'energy_percent', 'note']) !== [] || ! in_array($a['cleanliness'] ?? null, ['clean', 'dirty', null], true)) {
            throw new InvalidArgumentException('Assessment supports only canonical clean/dirty/null, observed energy and supplied note.');
        }
        $energy = $a['energy_percent'] ?? null;
        if ($energy !== null && (! is_int($energy) || $energy < 0 || $energy > 100)) {
            throw new InvalidArgumentException('Observed energy must be an integer from 0 through 100 or null.');
        }
        $n['assessment'] = ['cleanliness' => $a['cleanliness'] ?? null, 'energy_percent' => $energy, 'note' => ($a['note'] ?? null) === null ? null : $this->text($a['note'], 2000)];
        $n['reason'] = $this->text($r['reason'] ?? null, 2000);
        $n['attestation'] = $this->text($r['attestation'] ?? null, 2000);
        return $n;
    }

    private function text(mixed $value, int $limit): string
    {
        if (! is_string($value) || trim($value) === '' || mb_strlen($value) > $limit || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value)) {
            throw new InvalidArgumentException('Explicit bounded nonempty reason, attestation or supplied note required.');
        }
        return trim($value);
    }

    private function ownership(array $r): array
    {
        $vehicle = $this->facts->vehicleForCompany($r['company_id'], $r['vehicle_id']);
        $trip = $this->facts->tripSchedule($r['trip_id']);
        if ($vehicle === null || $trip === null || (int) $trip['fleet_vehicle_id'] !== $r['vehicle_id'] || $trip['deleted_at'] !== null || $trip['canceled_at'] !== null || ! in_array($trip['trip_status_code'], ['booked', 'in_progress', 'completed'], true)) {
            throw new RuntimeException('Recovery requires an owned active vehicle and noncanceled historical trip.');
        }
        $checklists = [];
        foreach (['pickup', 'return'] as $type) {
            $c = (new MovementChecklistRepository($this->db))->checklistForCompany($r['company_id'], $r[$type . '_checklist_id']);
            if ($c === null || (int) $c['fleet_vehicle_id'] !== $r['vehicle_id'] || (int) $c['turo_trip_normalized_id'] !== $r['trip_id'] || $c['movement_type'] !== $type) {
                throw new RuntimeException('Recovery checklist ownership mismatch.');
            }
            $checklists[$type] = $c;
        }
        return ['vehicle' => $vehicle, 'trip' => $trip, 'checklists' => $checklists,
            'handoff' => $this->facts->event($r['handoff_event_id']),
            'assessment' => $r['handoff_assessment_id'] === null ? null : $this->facts->assessment($r['handoff_assessment_id'])];
    }

    private function validate(array $r, array $owned, array $state, array $inputs, DateTimeImmutable $now): array
    {
        $at = $r['recovery']['occurred_at'];
        $events = $state['trip_movement_events'];
        $target = array_values(array_filter($events, static fn ($e) => (int) $e['turo_trip_normalized_id'] === $r['trip_id']));
        $handoffs = array_values(array_filter($target, static fn ($e) => $e['event_code'] === 'actual_handoff' && $e['voided_at'] === null));
        if (count($handoffs) !== 1 || (int) $handoffs[0]['id'] !== $r['handoff_event_id'] || $handoffs[0]['supersedes_event_id'] !== null || $handoffs[0]['movement_type'] !== 'pickup' || (int) $handoffs[0]['company_id'] !== $r['company_id']) {
            throw new RuntimeException('Exactly one active original authoritative handoff is required.');
        }
        $handoff = $handoffs[0];
        foreach ($events as $event) {
            if ($event['voided_at'] !== null) {
                continue;
            }
            if ((int) ($event['supersedes_event_id'] ?? 0) === $r['handoff_event_id']) {
                throw new RuntimeException('Preserved handoff is superseded.');
            }
            if ($event['occurred_at'] === $at || ($event['occurred_at'] === $handoff['occurred_at'] && (int) $event['id'] !== $r['handoff_event_id'] && in_array($event['event_code'], ['actual_handoff', 'vehicle_recovered', 'actual_return', 'guest_return_staged'], true))) {
                throw new RuntimeException('Ambiguous same-second movement ordering requires separate review.');
            }
        }
        foreach ($target as $event) {
            if (in_array($event['event_code'], ['vehicle_recovered', 'actual_return'], true)) {
                throw new RuntimeException('Existing recovery or equivalent return requires separate review.');
            }
            if ($event['company_id'] != $r['company_id'] || ($event['event_code'] === 'guest_return_staged' && ($event['occurred_at'] <= $handoff['occurred_at'] || $event['occurred_at'] >= $at))) {
                throw new RuntimeException('Conflicting return-report ownership or chronology.');
            }
        }
        $linked = [];
        foreach ($state['movement_assessments'] as $a) {
            if ((int) $a['turo_trip_normalized_id'] === $r['trip_id'] && $a['movement_type'] === 'return') {
                throw new RuntimeException('Existing equivalent return assessment requires separate review.');
            }
            if ((int) $a['trip_movement_event_id'] === $r['handoff_event_id'] && $a['voided_at'] === null) {
                if ((int) $a['company_id'] !== $r['company_id'] || (int) $a['turo_trip_normalized_id'] !== $r['trip_id'] || $a['movement_type'] !== 'pickup') {
                    throw new RuntimeException('Handoff assessment ownership mismatch.');
                }
                $linked[] = $a;
            }
        }
        if (count($linked) > 1 || (isset($linked[0]) ? (int) $linked[0]['id'] : null) !== $r['handoff_assessment_id']) {
            throw new RuntimeException('Preserved handoff assessment is missing or ambiguous.');
        }
        if ($at <= $handoff['occurred_at'] || $at >= $now->format('Y-m-d H:i:s') || $owned['trip']['ends_at'] >= $now->format('Y-m-d H:i:s')) {
            throw new RuntimeException('Recovery must follow the handoff on an already historical reservation.');
        }
        $custody = (new CurrentVehicleCustodyService())->resolveEvents(array_values(array_filter($inputs['timeline'], static fn ($e) => $e['occurred_at'] < $at)));
        if ($custody['custody'] !== 'guest' || $custody['active_trip_id'] !== $r['trip_id'] || $custody['integrity_conflict']) {
            throw new RuntimeException('Proposed recovery conflicts with another trip guest custody.');
        }
        return ['handoff' => $handoff, 'handoff_assessment' => $linked[0] ?? null,
            'later_events' => array_values(array_filter($events, static fn ($e) => $e['voided_at'] === null && $e['occurred_at'] > $at))];
    }

    private function virtualRows(array $r, int $actor): array
    {
        $event = ['id' => -1, 'company_id' => $r['company_id'], 'fleet_vehicle_id' => $r['vehicle_id'], 'turo_trip_normalized_id' => $r['trip_id'],
            'event_code' => 'vehicle_recovered', 'movement_type' => 'return', 'occurred_at' => $r['recovery']['occurred_at'],
            'location_class' => $r['recovery']['location_class'], 'location_detail' => $r['recovery']['location_detail'],
            'airport_garage_code' => $r['recovery']['airport_garage_code'], 'airport_parking_level' => $r['recovery']['airport_parking_level'], 'airport_parking_row' => $r['recovery']['airport_parking_row'],
            'source' => self::OPERATION, 'actor_user_id' => $actor, 'note' => $r['recovery']['location_note'] === null ? null : 'Recovery location detail: ' . $r['recovery']['location_note'],
            'supersedes_event_id' => null, 'voided_at' => null, 'voided_by_user_id' => null, 'void_reason' => null, 'created_at' => null, 'updated_at' => null];
        $assessment = ['id' => -2, 'company_id' => $r['company_id'], 'fleet_vehicle_id' => $r['vehicle_id'], 'turo_trip_normalized_id' => $r['trip_id'], 'trip_movement_event_id' => -1,
            'movement_type' => 'return', 'cleanliness' => $r['assessment']['cleanliness'], 'energy_percent' => $r['assessment']['energy_percent'], 'captured_at' => $r['recovery']['occurred_at'],
            'source' => self::OPERATION, 'actor_user_id' => $actor, 'note' => $r['assessment']['note'],
            'supersedes_assessment_id' => null, 'voided_at' => null, 'voided_by_user_id' => null, 'void_reason' => null, 'created_at' => null, 'updated_at' => null];
        if ($this->db->DBDriver === 'MySQLi') {
            foreach ([$event, $assessment] as $index => $row) {
                foreach ($row as &$value) {
                    if (is_int($value)) {
                        $value = (string) $value;
                    }
                }
                unset($value);
                if ($index === 0) {
                    $event = $row;
                } else {
                    $assessment = $row;
                }
            }
        }
        return [$event, $assessment];
    }

    private function mutate(array $r, int $actor, string $confirmation, array $owned, array $state, array $firewall, array $before, array $changes): array
    {
        $h = $r['recovery'];
        $eventId = (new MovementEventService($this->facts))->record(
            $r['vehicle_id'],
            $r['trip_id'],
            'vehicle_recovered',
            'return',
            $h['occurred_at'],
            $h['location_class'],
            $h['location_detail'],
            self::OPERATION,
            $actor,
            $h['location_note'] === null ? null : 'Recovery location detail: ' . $h['location_note'],
            ['garage_code' => $h['airport_garage_code'], 'level' => $h['airport_parking_level'], 'row' => $h['airport_parking_row']]
        );
        $assessmentId = (new MovementAssessmentService($this->facts))->record($r['vehicle_id'], $r['trip_id'], $eventId, 'return', $r['assessment']['cleanliness'], $r['assessment']['energy_percent'], $h['occurred_at'], self::OPERATION, $actor, $r['assessment']['note']);
        $event = $this->facts->event($eventId);
        $assessment = $this->facts->assessment($assessmentId);
        if ($event === null || $assessment === null) {
            throw new RuntimeException('Recovery artifacts could not be read back.');
        }
        $audit = ['operation' => self::OPERATION, 'operation_uuid' => $r['operation_uuid'], 'actor_user_id' => $actor, 'company_id' => $r['company_id'], 'vehicle_id' => $r['vehicle_id'], 'trip_id' => $r['trip_id'],
            'event_id' => $eventId, 'assessment_id' => $assessmentId, 'confirmation' => $confirmation, 'reason' => $r['reason'], 'attestation' => $r['attestation'],
            'preserved_handoff_id' => $r['handoff_event_id'], 'occurred_at' => $h['occurred_at'], 'recorded_at' => $event['created_at'], 'assessment' => $r['assessment']];
        $auditIds = [];
        foreach (['trip_movement_events' => [$eventId, 'historical_missing_recovery_created'], 'movement_assessments' => [$assessmentId, 'historical_missing_recovery_assessed']] as $table => [$id, $action]) {
            $this->repository->audit($r['company_id'], $table, $id, $action, $actor, [], $audit);
            $auditIds[] = (int) $this->db->insertID();
        }
        $now = new DateTimeImmutable('now', new DateTimeZone((new \Config\App())->appTimezone));
        $beforeViews = $this->projection->normalize($this->projection->views($r, $before['inputs'], $now));
        if ($beforeViews !== $this->projection->normalize($before['views'])) {
            throw new RuntimeException('Readiness time boundary changed; preview is stale.');
        }
        $expectedInputs = $this->projection->withRecovery($r, $before['inputs'], $event, $assessment);
        $expected = $this->projection->normalize($this->projection->views($r, $expectedInputs, $now), $eventId, $assessmentId);
        $afterState = $this->repository->state($r['vehicle_id']);
        $after = $this->projection->capture($r, $afterState, $now);
        $actual = $this->projection->normalize($after['views'], $eventId, $assessmentId);
        if ($expected !== $actual) {
            throw new RuntimeException('Canonical recovery projection differs from the frozen expected result; rolled back.');
        }
        $actualChanges = (new HistoricalMissingRecoveryPreservation())->compare($r, $before['inputs'], $beforeViews, $actual);
        if ($actualChanges !== $changes) {
            throw new RuntimeException('Recovery expected-change classification changed; rolled back.');
        }
        $result = ['operation' => self::OPERATION, 'status' => 'applied', 'operation_uuid' => $r['operation_uuid'], 'confirmation' => $confirmation,
            'event_id' => $eventId, 'assessment_id' => $assessmentId, 'creation_audit_id' => $auditIds[0], 'assessment_audit_id' => $auditIds[1],
            'before' => $beforeViews, 'after' => $actual, 'expected_changes' => $actualChanges, 'preservation' => true, 'positioning_separate' => true];
        $receipt = $audit + ['fingerprint' => $this->hash([$r, $actor]), 'event_hash' => $this->hash($event), 'assessment_hash' => $this->hash($assessment),
            'handoff_hash' => $this->hash($owned['handoff']), 'handoff_assessment_hash' => $this->hash($owned['assessment']),
            'creation_audit_hashes' => array_map(fn ($id) => $this->hash($this->repository->rows('operational_fact_audits', ['id' => $id], false, 1)[0]), $auditIds),
            'expected_changes_hash' => $this->hash($actualChanges), 'result' => $result, 'result_hash' => $this->hash($result)];
        $this->repository->audit($r['company_id'], 'turo_trips_normalized', $r['trip_id'], HistoricalMissingRecoveryRepairRepository::RECEIPT_ACTION, $actor, [], $receipt);
        $receiptId = (int) $this->db->insertID();
        $auditIds[] = $receiptId;
        $finalState = $this->repository->state($r['vehicle_id']);
        $protected = $finalState;
        foreach (['trip_movement_events' => [$eventId], 'movement_assessments' => [$assessmentId], 'operational_fact_audits' => $auditIds] as $table => $ids) {
            $protected[$table] = array_values(array_filter($protected[$table], static fn ($row) => ! in_array((int) $row['id'], $ids, true)));
        }
        $excluded = [$this->db->prefixTable('trip_movement_events') => [$eventId], $this->db->prefixTable('movement_assessments') => [$assessmentId], $this->db->prefixTable('operational_fact_audits') => $auditIds];
        $stored = $this->repository->commandReceipt($r['operation_uuid']);
        if ($protected !== $state || $this->repository->firewall($excluded) !== $firewall || $stored === null || $stored['receipt'] !== $receipt || (int) $stored['audit']['id'] !== $receiptId) {
            throw new RuntimeException('Final recovery write-set/receipt preservation failed; entire operation rolled back.');
        }
        return $result + ['receipt_audit_id' => $receiptId];
    }

    private function replay(array $r, int $actor, ?string $confirmation, array $row): array
    {
        $receipt = $row['receipt'];
        $result = $receipt['result'] ?? null;
        if (($receipt['operation'] ?? null) !== self::OPERATION || ($receipt['fingerprint'] ?? null) !== $this->hash([$r, $actor]) || ($confirmation !== null && ($receipt['confirmation'] ?? null) !== $confirmation)) {
            throw new RuntimeException('Operation UUID already used with different payload, actor or preview.');
        }
        if (! is_array($result) || ($result['status'] ?? null) !== 'applied' || ($result['operation_uuid'] ?? null) !== $r['operation_uuid'] || ($result['confirmation'] ?? null) !== ($receipt['confirmation'] ?? null)
            || ($result['event_id'] ?? null) !== ($receipt['event_id'] ?? null) || ($result['assessment_id'] ?? null) !== ($receipt['assessment_id'] ?? null)
            || ($result['preservation'] ?? null) !== true || ($receipt['result_hash'] ?? null) !== $this->hash($result) || ($receipt['expected_changes_hash'] ?? null) !== $this->hash($result['expected_changes'] ?? [])) {
            throw new RuntimeException('Historical recovery receipt/result integrity failed.');
        }
        $event = $this->facts->event((int) $result['event_id']);
        $assessment = $this->facts->assessment((int) $result['assessment_id']);
        if ($event === null || $assessment === null || $event['voided_at'] !== null || $assessment['voided_at'] !== null || $this->hash($event) !== $receipt['event_hash'] || $this->hash($assessment) !== $receipt['assessment_hash']
            || $this->hash($this->facts->event($r['handoff_event_id'])) !== $receipt['handoff_hash'] || $this->hash($r['handoff_assessment_id'] === null ? null : $this->facts->assessment($r['handoff_assessment_id'])) !== $receipt['handoff_assessment_hash']
            || (int) $row['audit']['company_id'] !== $r['company_id'] || $row['audit']['table_name'] !== 'turo_trips_normalized' || (int) $row['audit']['record_id'] !== $r['trip_id'] || (int) $row['audit']['actor_user_id'] !== $actor) {
            throw new RuntimeException('Historical recovery artifact preservation failed on replay.');
        }
        foreach (['creation_audit_id', 'assessment_audit_id'] as $index => $key) {
            $audit = $this->repository->rows('operational_fact_audits', ['id' => $result[$key]], false, 1)[0] ?? null;
            if ($audit === null || $this->hash($audit) !== $receipt['creation_audit_hashes'][$index]) {
                throw new RuntimeException('Historical recovery creation audit changed.');
            }
        }
        return array_replace($result, ['status' => 'already_applied', 'receipt_audit_id' => (int) $row['audit']['id'], 'preservation_at_apply' => true]);
    }

    private function hash(mixed $value): string
    {
        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR));
    }
}
