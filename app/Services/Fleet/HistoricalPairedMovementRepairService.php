<?php

namespace App\Services\Fleet;

use App\Repositories\GuestCommitmentProjectionRepository;
use App\Repositories\HistoricalMovementRepairRepository;
use App\Repositories\MovementChecklistRepository;
use App\Repositories\MovementReadinessReadModelRepository;
use App\Repositories\OperationalFactsRepository;
use App\Repositories\TripCommitmentRepository;
use App\Repositories\TripExtraFulfillmentRepository;
use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;

/** Privileged closed-interval repair. No ordinary movement command calls this service. */
final class HistoricalPairedMovementRepairService
{
    public const OPERATION = 'historical_paired_movement_repair.v1';
    private readonly HistoricalMovementRepairRepository $repository;
    private readonly OperationalFactsRepository $facts;
    private readonly CurrentVehicleCustodyService $custody;

    public function __construct(private readonly BaseConnection $db, ?HistoricalMovementRepairRepository $repository = null)
    {
        $this->repository = $repository ?? new HistoricalMovementRepairRepository($db);
        if ($this->repository->db !== $db) {
            throw new InvalidArgumentException('Repair requires one database connection.');
        }
        $this->facts = new OperationalFactsRepository($db);
        $this->custody = new CurrentVehicleCustodyService($this->facts);
    }

    /** SELECT-only preview; never a write followed by rollback. @return array<string, mixed> */
    public function preview(array $request, int $actor): array
    {
        return $this->execute($request, $actor, null);
    }

    /** @return array<string, mixed> */
    public function apply(array $request, int $actor, string $confirmation): array
    {
        if (! preg_match('/^[a-f0-9]{64}$/D', $confirmation)) {
            throw new InvalidArgumentException('Confirm the exact read-only preview hash.');
        }
        return $this->execute($request, $actor, $confirmation);
    }

    /** @return array<string, mixed> */
    private function execute(array $request, int $actor, ?string $confirmation): array
    {
        $request = $this->normalize($request);
        if ($this->db->transDepth !== 0 || ! in_array($this->db->DBDriver, ['MySQLi', 'SQLite3'], true)) {
            throw new RuntimeException('Repair must own a supported root transaction.');
        }
        if ($this->db->DBDriver === 'MySQLi') {
            $this->db->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            if ($confirmation === null) {
                $this->db->query('SET TRANSACTION READ ONLY');
            }
        }
        if (! $this->db->transBegin()) {
            throw new RuntimeException('Repair transaction could not begin.');
        }
        try {
            $this->repository->requireTransactionalTables();
            $this->repository->authorize($actor, $confirmation !== null);
            if ($confirmation !== null) {
                $this->repository->lockVehicle($request['company_id'], $request['vehicle_id']);
            }
            $now = new DateTimeImmutable('now', new DateTimeZone((new \Config\App())->appTimezone));
            $ownership = $this->ownership($request);
            $receipt = $this->repository->receipt($request['company_id'], $request['trip_id']);
            if ($receipt !== null) {
                $result = $this->replay($request, $receipt);
            } else {
                $state = $this->repository->vehicleState($request['vehicle_id']);
                $before = $this->authority($request, $now);
                $readiness = $this->readiness($request, $ownership['trip'], $state, $now);
                $commercial = (new GuestCommitmentProjectionRepository($this->db))->load($request['company_id'], array_map('intval', array_column($state['turo_trips_normalized'], 'id')), $now);
                $projection = $this->validateProjection($request, $ownership, $state, $before, $now);
                $hash = $this->hash([self::OPERATION, $request, $actor, $state, $before, $readiness['contexts'], $commercial]);
                $result = [
                    'operation' => self::OPERATION, 'status' => 'preview', 'confirmation' => $hash,
                    'creates' => ['actual_handoff' => $request['handoff'], 'vehicle_recovered' => $request['recovery'], 'assessment' => 'return: cleanliness/energy not captured'],
                    'voids' => $request['invalid_fact'], 'before' => $before, 'projected_after' => $before,
                    'historical_interval_closed' => true, 'later_trip_ids' => $readiness['trip_ids'],
                    'historical_movement_gaps' => $projection['gaps'], 'preservation' => 'projected; readiness rechecked atomically on apply',
                ];
                if ($confirmation !== null) {
                    if (! hash_equals($hash, $confirmation)) {
                        throw new RuntimeException('Preview is stale; read and confirm a fresh preview.');
                    }
                    $result = $this->mutate($request, $actor, $ownership, $state, $before, $readiness, $commercial, $projection['gaps']);
                }
            }
            if (! $this->db->transStatus() || ! $this->db->transCommit()) {
                throw new RuntimeException('Repair transaction failed; no successful result can be assumed.');
            }
            return $result;
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }

    /** @return array<string, mixed> */
    private function normalize(array $r): array
    {
        $keys = ['company_id', 'vehicle_id', 'trip_id', 'pickup_checklist_id', 'return_checklist_id', 'handoff', 'recovery', 'reason', 'context', 'assessment_mode', 'expected_authority', 'invalid_fact'];
        if (array_diff(array_keys($r), $keys) !== []) {
            throw new InvalidArgumentException('Unsupported repair fields, including measurements, are rejected.');
        }
        $n = [];
        foreach (array_slice($keys, 0, 5) as $key) {
            $n[$key] = $this->positiveId($r[$key] ?? null);
        }
        foreach (['handoff', 'recovery'] as $key) {
            $value = $r[$key] ?? null;
            if (! is_array($value) || array_diff(array_keys($value), ['occurred_at', 'location_class', 'location_detail']) !== []) {
                throw new InvalidArgumentException('A complete historical handoff and recovery are required.');
            }
            $time = $value['occurred_at'] ?? '';
            $parsed = is_string($time) ? DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $time, new DateTimeZone((new \Config\App())->appTimezone)) : false;
            if (! $parsed || $parsed->format('Y-m-d H:i:s') !== $time) {
                throw new InvalidArgumentException('Use a valid local Y-m-d H:i:s occurrence in the application timezone.');
            }
            $class = $value['location_class'] ?? '';
            $detail = $this->text($value['location_detail'] ?? null, 500);
            if (! is_string($class) || ! in_array($class, ['home', 'airport_hnl', 'waikiki_hotel', 'other_delivery'], true)) {
                throw new InvalidArgumentException('An attested known location is required.');
            }
            (new LocationClassificationService())->explicit($class, $detail);
            $n[$key] = ['occurred_at' => $time, 'location_class' => $class, 'location_detail' => $detail];
        }
        if ($n['handoff']['occurred_at'] >= $n['recovery']['occurred_at']) {
            throw new InvalidArgumentException('Handoff must strictly precede recovery.');
        }
        $n['reason'] = $this->text($r['reason'] ?? null, 2000);
        $n['context'] = $this->text($r['context'] ?? null, 200);
        if (($r['assessment_mode'] ?? null) !== 'not_captured') {
            throw new InvalidArgumentException('This narrow repair supports only explicit not_captured assessments.');
        }
        $n['assessment_mode'] = 'not_captured';
        $expected = $r['expected_authority'] ?? null;
        if (! is_array($expected) || array_diff(array_keys($expected), ['custody_event_id', 'position_event_id', 'latest_movement_event_id']) !== []) {
            throw new InvalidArgumentException('Explicit expected later authority is required.');
        }
        foreach (['custody_event_id', 'position_event_id', 'latest_movement_event_id'] as $key) {
            $n['expected_authority'][$key] = $this->positiveId($expected[$key] ?? null);
        }
        $n['invalid_fact'] = null;
        if (($r['invalid_fact'] ?? null) !== null) {
            $invalid = $r['invalid_fact'];
            if (! is_array($invalid) || array_diff(array_keys($invalid), ['event_id', 'assessment_id', 'reason']) !== [] || ! array_key_exists('assessment_id', $invalid)) {
                throw new InvalidArgumentException('Identify the exact invalid event and linked assessment, or explicit null.');
            }
            $n['invalid_fact'] = ['event_id' => $this->positiveId($invalid['event_id'] ?? null), 'assessment_id' => $invalid['assessment_id'] === null ? null : $this->positiveId($invalid['assessment_id']), 'reason' => $this->text($invalid['reason'] ?? null, 2000)];
        }
        return $n;
    }

    private function positiveId(mixed $id): int
    {
        if (! is_int($id) || $id < 1) {
            throw new InvalidArgumentException('Explicit positive integer identifiers are required.');
        }
        return $id;
    }

    private function text(mixed $value, int $max): string
    {
        if (! is_string($value) || trim($value) === '' || mb_strlen($value) > $max || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value)) {
            throw new InvalidArgumentException('A nonempty bounded attestation/reason is required.');
        }
        return trim($value);
    }

    /** @return array<string, mixed> */
    private function ownership(array $r): array
    {
        $vehicle = $this->facts->vehicle($r['vehicle_id']);
        $trip = $this->facts->tripSchedule($r['trip_id']);
        if ($vehicle === null || ($vehicle['deleted_at'] ?? null) !== null || (int) $vehicle['company_id'] !== $r['company_id'] || $trip === null || (int) $trip['fleet_vehicle_id'] !== $r['vehicle_id'] || ($trip['deleted_at'] ?? null) !== null || ($trip['canceled_at'] ?? null) !== null || ($trip['trip_status_code'] ?? null) !== 'completed') {
            throw new RuntimeException('Repair requires an owned, active vehicle and completed historical trip.');
        }
        $checklists = new MovementChecklistRepository($this->db);
        $result = ['trip' => $trip];
        foreach (['pickup', 'return'] as $type) {
            $checklist = $checklists->checklistForCompany($r['company_id'], $r[$type . '_checklist_id']);
            if ($checklist === null || (int) $checklist['turo_trip_normalized_id'] !== $r['trip_id'] || (int) $checklist['fleet_vehicle_id'] !== $r['vehicle_id'] || $checklist['movement_type'] !== $type) {
                throw new RuntimeException('Checklist does not belong to this company, vehicle, trip and movement.');
            }
            $result[$type] = array_merge($checklist, ['exists' => true]);
        }
        return $result;
    }

    /** @return array<string, mixed> */
    private function authority(array $r, DateTimeImmutable $now): array
    {
        $custody = $this->custody->resolve($r['vehicle_id'], $now);
        $position = (new CurrentVehicleLocationService($this->facts, $this->custody))->resolve($r['vehicle_id'], $now);
        unset($position['age_seconds']);
        $latest = $this->facts->latestCurrentStateEvent($r['vehicle_id'], $now->format('Y-m-d H:i:s'));
        $latest = $latest === null ? null : array_intersect_key($latest, array_flip(['id', 'company_id', 'fleet_vehicle_id', 'turo_trip_normalized_id', 'event_code', 'occurred_at']));
        return ['custody' => $this->compactCustody($custody), 'position' => $position, 'latest_movement' => $latest];
    }

    private function compactCustody(array $state): array
    {
        unset($state['basis_event'], $state['conflicting_guest_states']);
        return $state;
    }

    /** Pure custody counterfactual using the unchanged production projection. */
    private function validateProjection(array $r, array $owned, array $state, array $before, DateTimeImmutable $now): array
    {
        $recovery = $r['recovery']['occurred_at'];
        if ($recovery >= $now->format('Y-m-d H:i:s') || $before['custody']['integrity_conflict']) {
            throw new RuntimeException('Repair requires a past interval and conflict-free current custody.');
        }
        foreach ([['custody_event_id', $before['custody']['basis_event_id'], $before['custody']['occurred_at']], ['position_event_id', $before['position']['event_id'], $before['position']['observed_at']], ['latest_movement_event_id', $before['latest_movement']['id'] ?? null, $before['latest_movement']['occurred_at'] ?? null]] as [$key, $id, $at]) {
            if ($r['expected_authority'][$key] !== (int) $id || $at === null || $at <= $recovery) {
                throw new RuntimeException('Expected genuine later custody, position and movement authority must all dominate recovery.');
            }
        }
        if ((int) $before['custody']['basis_trip_id'] === $r['trip_id'] || ! in_array($before['custody']['basis_event_code'], ['actual_handoff', 'actual_return', 'vehicle_recovered'], true)) {
            throw new RuntimeException('A different trip must provide genuine later custody authority.');
        }
        $window = (new \Config\MovementIntelligence())->repairCandidateWindowHours * 3600;
        foreach (['handoff' => 'starts_at', 'recovery' => 'ends_at'] as $field => $scheduled) {
            if (abs((new DateTimeImmutable($r[$field]['occurred_at']))->getTimestamp() - (new DateTimeImmutable($owned['trip'][$scheduled]))->getTimestamp()) > $window) {
                throw new RuntimeException('Occurrence is outside the historical trip repair window.');
            }
        }
        $invalid = $r['invalid_fact'];
        if ($invalid !== null) {
            $event = $this->facts->event($invalid['event_id']);
            $assessments = $this->repository->rows('movement_assessments', ['trip_movement_event_id' => $invalid['event_id'], 'voided_at' => null], false, 2);
            if ($event === null || $event['voided_at'] !== null || $event['event_code'] !== 'vehicle_staged' || (int) $event['company_id'] !== $r['company_id'] || (int) $event['fleet_vehicle_id'] !== $r['vehicle_id'] || (int) $event['turo_trip_normalized_id'] !== $r['trip_id'] || $event['movement_type'] !== 'pickup' || count($assessments) > 1 || (isset($assessments[0]) ? (int) $assessments[0]['id'] : null) !== $invalid['assessment_id']) {
                throw new RuntimeException('Invalid staging/assessment targets do not match the owned historical pickup.');
            }
            if (isset($assessments[0]) && ((int) $assessments[0]['company_id'] !== $r['company_id'] || (int) $assessments[0]['fleet_vehicle_id'] !== $r['vehicle_id'] || (int) $assessments[0]['turo_trip_normalized_id'] !== $r['trip_id'] || $assessments[0]['movement_type'] !== 'pickup')) {
                throw new RuntimeException('Linked assessment ownership does not match the historical pickup.');
            }
            foreach ($state['vehicle_positioning_plans'] as $plan) {
                if (($plan['invalidated_at'] ?? null) === null) {
                    throw new RuntimeException('Accepted void would invalidate an active positioning plan; resolve separately before repair.');
                }
            }
        }
        $timeline = $this->facts->activeCustodyTimelineEvents([$r['vehicle_id']], $now->format('Y-m-d H:i:s'), $r['company_id']);
        $timeline = array_values(array_filter($timeline, static fn (array $e): bool => (int) $e['id'] !== ($invalid['event_id'] ?? 0)));
        foreach ($state['trip_movement_events'] as $event) {
            if ($event['voided_at'] === null && (int) $event['id'] !== ($invalid['event_id'] ?? 0) && in_array($event['event_code'], ['vehicle_staged', 'actual_handoff', 'actual_return', 'vehicle_recovered', 'vehicle_positioned'], true) && $event['occurred_at'] >= $r['handoff']['occurred_at'] && $event['occurred_at'] <= $recovery) {
                throw new RuntimeException('An observed movement overlaps the proposed interval.');
            }
        }
        foreach ($timeline as $event) {
            if ((int) $event['turo_trip_normalized_id'] === $r['trip_id'] && in_array($event['event_code'], ['actual_handoff', 'actual_return', 'vehicle_recovered', 'guest_return_staged'], true)) {
                throw new RuntimeException('An existing historical custody fact requires separate review.');
            }
            if ($event['occurred_at'] >= $r['handoff']['occurred_at'] && $event['occurred_at'] <= $recovery) {
                throw new RuntimeException('An observed movement overlaps the proposed interval.');
            }
        }
        $prior = $this->custody->resolveEvents(array_values(array_filter($timeline, static fn (array $e): bool => $e['occurred_at'] < $r['handoff']['occurred_at'])));
        if ($prior['custody'] === 'guest' || $prior['integrity_conflict']) {
            throw new RuntimeException('The proposed handoff overlaps existing guest custody.');
        }
        foreach (['handoff' => 'actual_handoff', 'recovery' => 'vehicle_recovered'] as $field => $code) {
            $timeline[] = array_merge($r[$field], [
                'id' => $field === 'handoff' ? -2 : -1, 'company_id' => $r['company_id'], 'fleet_vehicle_id' => $r['vehicle_id'], 'turo_trip_normalized_id' => $r['trip_id'], 'event_code' => $code,
                'custody_trip_starts_at' => $owned['trip']['starts_at'], 'custody_trip_ends_at' => $owned['trip']['ends_at'], 'custody_trip_status_code' => 'completed', 'custody_trip_canceled_at' => null,
            ]);
        }
        $projected = $this->custody->resolveEvents($timeline);
        $closed = $this->custody->resolveEvents(array_values(array_filter($timeline, static fn (array $e): bool => $e['occurred_at'] <= $recovery)));
        if ($this->compactCustody($projected) !== $before['custody'] || $closed['custody'] !== 'operator' || (int) $closed['basis_trip_id'] !== $r['trip_id'] || $closed['integrity_conflict']) {
            throw new RuntimeException('Projected pair fails closure or later custody preservation.');
        }
        $gaps = [];
        foreach ($state['turo_trips_normalized'] as $trip) {
            if ($trip['starts_at'] > $owned['trip']['starts_at'] && $trip['starts_at'] < ($before['custody']['occurred_at'] ?? '') && ! array_filter($timeline, static fn (array $e): bool => (int) $e['turo_trip_normalized_id'] === (int) $trip['id'])) {
                $gaps[] = (int) $trip['id'];
            }
        }
        return ['gaps' => $gaps];
    }

    private function readiness(array $r, array $trip, array $state, DateTimeImmutable $now, ?array $savedContexts = null, ?array $savedCommercial = null): array
    {
        $tripIds = array_map('intval', array_column(array_values(array_filter($state['turo_trips_normalized'], static fn (array $t): bool => (int) $t['id'] !== $r['trip_id'] && $t['starts_at'] >= $trip['starts_at'])), 'id'));
        foreach ($state['trip_movement_events'] as $event) {
            $eventTrip = (int) ($event['turo_trip_normalized_id'] ?? 0);
            if ($eventTrip > 0 && $eventTrip !== $r['trip_id'] && $event['voided_at'] === null && $event['occurred_at'] > $r['recovery']['occurred_at']) {
                $tripIds[] = $eventTrip;
            }
        }
        $tripIds = array_values(array_unique($tripIds));
        sort($tripIds);
        $ids = array_map('intval', array_column(array_values(array_filter($state['trip_movement_checklists'], static fn (array $c): bool => in_array((int) $c['turo_trip_normalized_id'], $tripIds, true))), 'id'));
        $repository = $savedContexts === null ? new MovementReadinessReadModelRepository($this->db) : new class ($this->db, $savedContexts) extends MovementReadinessReadModelRepository {
            public function __construct(BaseConnection $db, private readonly array $contexts)
            {
                parent::__construct($db);
            }
            public function loadForCompany(int $companyId, array $checklistIds, DateTimeImmutable $asOf): array
            {
                return $this->contexts;
            }
        };
        $commitments = new TripCommitmentService(new TripCommitmentRepository($this->db));
        $fulfillment = new TripExtraFulfillmentService(new TripExtraFulfillmentRepository($this->db));
        $commercialRepository = $savedCommercial === null ? new GuestCommitmentProjectionRepository($this->db) : new class ($this->db, $savedCommercial) extends GuestCommitmentProjectionRepository {
            public function __construct(BaseConnection $db, private readonly array $inputs)
            {
                parent::__construct($db);
            }
            public function load(int $companyId, array $tripIds, DateTimeImmutable $asOf): array
            {
                return $this->inputs;
            }
        };
        $projection = new GuestCommitmentProjectionService($commercialRepository, $fulfillment, $commitments);
        $service = new MovementReadinessReadService($repository, new MovementReadinessProjectionService(), $commitments, new TripEnergyRuleResolver(new TripCommitmentRepository($this->db)), $fulfillment, null, $projection);
        return ['trip_ids' => $tripIds, 'contexts' => $repository->loadForCompany($r['company_id'], $ids, $now), 'projected' => $service->forCompany($r['company_id'], $ids, $now)];
    }

    private function mutate(array $r, int $actor, array $owned, array $state, array $before, array $readiness, array $commercial, array $gaps): array
    {
        $events = new MovementEventService($this->facts);
        $assessments = new MovementAssessmentService($this->facts);
        if ($r['invalid_fact'] !== null) {
            $invalid = $r['invalid_fact'];
            $void = new MovementOperationalFactService($this->db, $events, $assessments);
            if (! $void->voidInvalidFact($owned['pickup'], ['event_id' => $invalid['event_id'], 'assessment_id' => $invalid['assessment_id'], 'void_reason' => self::OPERATION . ': ' . $invalid['reason']], $actor)) {
                throw new RuntimeException('Invalid fact could not be voided.');
            }
        }
        $created = [];
        foreach (['handoff' => ['actual_handoff', 'pickup'], 'recovery' => ['vehicle_recovered', 'return']] as $field => [$code, $type]) {
            $location = $r[$field];
            $id = $events->record($r['vehicle_id'], $r['trip_id'], $code, $type, $location['occurred_at'], $location['location_class'], $location['location_detail'], 'historical_custody_repair', $actor, self::OPERATION . ': ' . $r['reason']);
            if ($id < 1) {
                throw new RuntimeException('Historical movement creation failed.');
            }
            $created[$field] = ['id' => $id, 'hash' => $this->hash($this->facts->event($id))];
            $this->repository->audit($r['company_id'], 'trip_movement_events', $id, 'historical_pair_created', $actor, [], ['operation' => self::OPERATION, 'vehicle_id' => $r['vehicle_id'], 'trip_id' => $r['trip_id'], 'reason' => $r['reason'], 'context' => $r['context'], 'event_code' => $code, 'attestation' => $location]);
        }
        $assessmentId = $assessments->record($r['vehicle_id'], $r['trip_id'], $created['recovery']['id'], 'return', null, null, $r['recovery']['occurred_at'], 'historical_custody_repair', $actor, 'Energy unknown: not captured at historical recovery. Cleanliness not captured.');
        if ($assessmentId < 1) {
            throw new RuntimeException('Historical not-captured assessment creation failed.');
        }
        $created['assessment'] = ['id' => $assessmentId, 'hash' => $this->hash($this->facts->assessment($assessmentId))];
        $this->repository->audit($r['company_id'], 'movement_assessments', $assessmentId, 'historical_pair_created', $actor, [], ['operation' => self::OPERATION, 'vehicle_id' => $r['vehicle_id'], 'trip_id' => $r['trip_id'], 'reason' => $r['reason'], 'assessment_mode' => 'not_captured']);
        // Read at a time after creation, so created_at visibility filters cannot
        // hide a new fact when the transaction crosses a wall-clock second.
        $afterNow = new DateTimeImmutable('now', new DateTimeZone((new \Config\App())->appTimezone));
        $after = $this->authority($r, $afterNow);
        $afterState = $this->repository->vehicleState($r['vehicle_id']);
        $beforeReadiness = $this->readiness($r, $owned['trip'], $state, $afterNow, $readiness['contexts'], $commercial);
        $afterReadiness = $this->readiness($r, $owned['trip'], $afterState, $afterNow);
        $afterCommercial = (new GuestCommitmentProjectionRepository($this->db))->load($r['company_id'], array_map('intval', array_column($afterState['turo_trips_normalized'], 'id')), $afterNow);
        // This loader includes movement handoffs by design; everything commercial
        // other than the repaired trip's newly recorded handoff must stay identical.
        $commercial['handoffs'] = array_values(array_filter($commercial['handoffs'], static fn ($id): bool => (int) $id !== $r['trip_id']));
        $afterCommercial['handoffs'] = array_values(array_filter($afterCommercial['handoffs'], static fn ($id): bool => (int) $id !== $r['trip_id']));
        if ($before !== $after || $beforeReadiness['projected'] !== $afterReadiness['projected'] || $commercial !== $afterCommercial || $this->protectedState($state, $r) !== $this->protectedState($afterState, $r, $created)) {
            throw new RuntimeException('Later authority/readiness or unrelated state changed; entire repair rolled back.');
        }
        $receipt = ['operation' => self::OPERATION, 'fingerprint' => $this->hash($r), 'company_id' => $r['company_id'], 'vehicle_id' => $r['vehicle_id'], 'trip_id' => $r['trip_id'], 'actor_user_id' => $actor, 'reason' => $r['reason'], 'context' => $r['context'], 'handoff' => $r['handoff'], 'recovery' => $r['recovery'], 'invalid_fact' => $r['invalid_fact'], 'created' => $created, 'before' => $before, 'after' => $after, 'preservation' => true, 'readiness_before_hash' => $this->hash($beforeReadiness['projected']), 'readiness_after_hash' => $this->hash($afterReadiness['projected']), 'protected_state_hash' => $this->hash($this->protectedState($state, $r)), 'historical_movement_gaps' => $gaps];
        $this->repository->audit($r['company_id'], 'turo_trips_normalized', $r['trip_id'], 'historical_pair_applied', $actor, [], $receipt);
        $closed = $this->custody->resolve($r['vehicle_id'], new DateTimeImmutable($r['recovery']['occurred_at']));
        if ($closed['custody'] !== 'operator' || $closed['integrity_conflict'] || $closed['basis_event_id'] !== $created['recovery']['id'] || $this->repository->vehicleState($r['vehicle_id']) !== $afterState || $this->authority($r, $afterNow) !== $after || $this->repository->receipt($r['company_id'], $r['trip_id']) !== $receipt) {
            throw new RuntimeException('Final repair/receipt preservation failed; entire repair rolled back.');
        }
        return ['operation' => self::OPERATION, 'status' => 'applied', 'created' => $created, 'before' => $before, 'after' => $after, 'historical_movement_gaps' => $gaps, 'preservation' => true];
    }

    private function protectedState(array $state, array $r, array $created = []): array
    {
        foreach (['trip_movement_events' => 'event_id', 'movement_assessments' => 'assessment_id'] as $table => $target) {
            $newIds = $table === 'trip_movement_events' ? array_column(array_intersect_key($created, array_flip(['handoff', 'recovery'])), 'id') : [$created['assessment']['id'] ?? 0];
            $state[$table] = array_values(array_filter($state[$table], static fn (array $row): bool => ! in_array((int) $row['id'], $newIds, true)));
            foreach ($state[$table] as &$row) {
                if ((int) $row['id'] === ($r['invalid_fact'][$target] ?? 0)) {
                    unset($row['voided_at'], $row['voided_by_user_id'], $row['void_reason'], $row['updated_at']);
                }
            }
            unset($row);
        }
        return $state;
    }

    private function replay(array $r, array $receipt): array
    {
        if (($receipt['operation'] ?? null) !== self::OPERATION || ($receipt['fingerprint'] ?? null) !== $this->hash($r)) {
            throw new RuntimeException('A materially different repair already exists for this trip; operator review required.');
        }
        foreach (['handoff', 'recovery', 'assessment'] as $key) {
            $target = $receipt['created'][$key] ?? null;
            $row = $key === 'assessment' ? $this->facts->assessment((int) ($target['id'] ?? 0)) : $this->facts->event((int) ($target['id'] ?? 0));
            if ($row === null || ($row['voided_at'] ?? null) !== null || ($target['hash'] ?? '') !== $this->hash($row)) {
                throw new RuntimeException('Prior repair facts changed or are incomplete; operator review required.');
            }
        }
        if ($r['invalid_fact'] !== null) {
            foreach (['event_id', 'assessment_id'] as $key) {
                $id = $r['invalid_fact'][$key];
                $row = $id === null ? null : ($key === 'event_id' ? $this->facts->event($id) : $this->facts->assessment($id));
                if ($id !== null && ($row === null || ($row['voided_at'] ?? null) === null)) {
                    throw new RuntimeException('Prior invalid-fact treatment is incomplete.');
                }
            }
        }
        return ['operation' => self::OPERATION, 'status' => 'already_applied', 'created' => $receipt['created'], 'preservation_at_apply' => $receipt['preservation']];
    }

    private function hash(mixed $value): string
    {
        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR));
    }
}
