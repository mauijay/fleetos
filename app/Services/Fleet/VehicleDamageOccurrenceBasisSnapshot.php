<?php

namespace App\Services\Fleet;

use App\Repositories\VehicleDamageOccurrenceRepository;
use App\Repositories\VehicleDamageRepository;
use CodeIgniter\Database\BaseConnection;
use Config\VehicleDamage;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use JsonException;

/** Historical comparison evidence, never a source of current condition state. */
final class VehicleDamageOccurrenceBasisSnapshot
{
    public const VERSION = 1;
    public const MAX_BYTES = 32768;
    public const UNAVAILABLE = 'Comparison basis not recorded / unsupported';

    public function __construct(private readonly BaseConnection $db)
    {
    }

    /** Caller owns the transaction; only selected IDs enter, never client-supplied snapshot JSON.
     * @param list<int> $evidenceIds @return array<string,mixed>
     */
    public function build(int $company, int $vehicle, ?string $panel, string $type, string $recordedAt, ?int $conditionId = null, ?int $priorMembershipId = null, ?int $tripId = null, ?int $baselineEventId = null, ?int $baselineMembershipId = null, array $evidenceIds = []): array
    {
        if ($this->db->transDepth < 1) {
            throw new InvalidArgumentException('Build occurrence basis inside the owning transaction.');
        }
        $items = new VehicleDamageRepository($this->db);
        $items->lockVehicle($company, $vehicle);
        $occurrences = new VehicleDamageOccurrenceRepository($this->db);
        $occurrences->requireReady();
        $prior = null;
        if ($conditionId !== null) {
            $items->lockItem($company, $vehicle, $conditionId);
            $item = $items->item($company, $vehicle, $conditionId);
            $event = $this->db->table('vehicle_damage_item_events')->where('company_id', $company)->where('vehicle_damage_item_id', $conditionId)->orderBy('id', 'DESC')->get(1)->getRowArray();
            if ($item === null || ($item['current_condition_item_id'] ?? null) !== null || $event === null) {
                throw new InvalidArgumentException('Choose an owned canonical condition with recorded event history.');
            }
            $prior = ['canonical_condition_item_id' => $conditionId, 'latest_condition_event_id' => (int) $event['id'], 'status_code' => $item['status_code'], 'severity_code' => $item['severity_code'], 'description' => $item['description'], 'discovered_at' => $item['discovered_at']];
        }
        $occurrence = null;
        if ($priorMembershipId !== null) {
            $member = $occurrences->membership($company, $vehicle, $priorMembershipId);
            if ($member === null) {
                throw new InvalidArgumentException('Prior occurrence must have owned parents.');
            }
            $occurrence = ['incident_item_id' => $priorMembershipId, 'condition_event_id' => isset($member['condition_event_id']) ? (int) $member['condition_event_id'] : null, 'effect_code' => $member['effect_code'], 'note' => $member['note'], 'occurred_at' => $member['incident_occurred_at'], 'discovered_at' => $member['incident_discovered_at'], 'recorded_at' => $member['created_at']];
        }
        $comparison = $this->boundary($company, $vehicle, $tripId) + ['baseline' => null];
        if ($baselineEventId !== null) {
            $row = $this->db->table('vehicle_damage_item_events')->where('id', $baselineEventId)->where('company_id', $company)->get()->getRowArray();
            $event = $row === null ? null : $occurrences->event($company, $vehicle, (int) $row['vehicle_damage_item_id'], $baselineEventId);
            if ($event === null) {
                throw new InvalidArgumentException('Baseline event must have owned parents.');
            }
            $observed = $event['event_code'] === 'observed_existing';
            $comparison['baseline'] = ['kind' => $observed ? 'observed_existing' : 'recorded_condition', 'canonical_condition_item_id' => (int) $event['vehicle_damage_item_id'], 'condition_event_id' => $baselineEventId, 'incident_item_id' => $baselineMembershipId, 'observed_at' => $observed ? $event['occurred_at'] : null, 'recorded_at' => $event['created_at'], 'evidence_ids' => $evidenceIds];
        } elseif ($baselineMembershipId !== null || $evidenceIds !== []) {
            throw new InvalidArgumentException('Baseline evidence requires an explicit baseline event.');
        }
        $snapshot = ['schema_version' => self::VERSION, 'snapshot_recorded_at' => $recordedAt, 'panel_code' => $panel, 'damage_type_code' => $type, 'prior_condition' => $prior, 'prior_occurrence' => $occurrence, 'comparison' => $comparison];
        $this->validateForWrite($company, $vehicle, $snapshot);
        return $snapshot;
    }

    /** Structure validation tolerates additional keys but never truncates. @param array<string,mixed> $snapshot */
    public static function encode(array $snapshot): string
    {
        self::validateStructure($snapshot);
        $json = json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (strlen($json) > self::MAX_BYTES) {
            throw new InvalidArgumentException('Occurrence basis exceeds 32768 UTF-8 bytes.');
        }
        return $json;
    }

    /** Stored snapshots are read as recorded, with no reconstruction or writes.
     * @return array{state:string,snapshot:?array<string,mixed>,message:?string}
     */
    public static function read(?string $json): array
    {
        if ($json === null) {
            return ['state' => 'not_recorded', 'snapshot' => null, 'message' => self::UNAVAILABLE];
        }
        try {
            $snapshot = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($snapshot) || ! is_int($snapshot['schema_version'] ?? null) || strlen($json) > self::MAX_BYTES) {
                throw new InvalidArgumentException('Invalid stored occurrence basis.');
            }
            if ($snapshot['schema_version'] !== self::VERSION) {
                return ['state' => 'unsupported', 'snapshot' => null, 'message' => self::UNAVAILABLE];
            }
            self::validateStructure($snapshot);
            return ['state' => 'recorded', 'snapshot' => $snapshot, 'message' => null];
        } catch (InvalidArgumentException | JsonException) {
            return ['state' => 'invalid', 'snapshot' => null, 'message' => self::UNAVAILABLE];
        }
    }

    /** @param array<string,mixed> $snapshot */
    public function validateForWrite(int $company, int $vehicle, array $snapshot): string
    {
        $json = self::encode($snapshot);
        $items = new VehicleDamageRepository($this->db);
        $occurrences = new VehicleDamageOccurrenceRepository($this->db);
        $occurrences->requireReady();
        if ($items->vehicle($company, $vehicle) === null) {
            throw new InvalidArgumentException('Occurrence basis requires an owned vehicle.');
        }
        $prior = $snapshot['prior_condition'];
        if ($prior !== null) {
            $item = $this->condition($company, $vehicle, $prior['canonical_condition_item_id'], $snapshot);
            $event = $occurrences->event($company, $vehicle, (int) $item['id'], $prior['latest_condition_event_id']);
            if ($event === null || $event['created_at'] > $snapshot['snapshot_recorded_at'] || $prior['discovered_at'] !== $item['discovered_at']
                || $prior['status_code'] !== $event['new_status_code'] || $prior['severity_code'] !== $event['new_severity_code'] || $prior['description'] !== $event['new_description']) {
                throw new InvalidArgumentException('Prior condition basis must match its exact owned event state.');
            }
        }
        $priorOccurrence = $snapshot['prior_occurrence'];
        if ($priorOccurrence !== null) {
            $member = $occurrences->membership($company, $vehicle, $priorOccurrence['incident_item_id']);
            if ($member === null || $prior === null || (int) $member['vehicle_damage_item_id'] !== $prior['canonical_condition_item_id']
                || ($member['panel_code'] ?? null) !== $snapshot['panel_code'] || $member['damage_type_code'] !== $snapshot['damage_type_code']
                || (isset($member['condition_event_id']) ? (int) $member['condition_event_id'] : null) !== $priorOccurrence['condition_event_id']
                || $member['effect_code'] !== $priorOccurrence['effect_code'] || $member['note'] !== $priorOccurrence['note']
                || $member['incident_occurred_at'] !== $priorOccurrence['occurred_at'] || $member['incident_discovered_at'] !== $priorOccurrence['discovered_at'] || $member['created_at'] !== $priorOccurrence['recorded_at']) {
                throw new InvalidArgumentException('Prior occurrence basis must match its owned membership.');
            }
        }
        $comparison = $snapshot['comparison'];
        $boundary = $this->boundary($company, $vehicle, $comparison['trip_id']);
        foreach (['trip_id', 'boundary_kind', 'boundary_at'] as $key) {
            if ($comparison[$key] !== $boundary[$key]) {
                throw new InvalidArgumentException('Comparison boundary must match the owned trip authority.');
            }
        }
        $baseline = $comparison['baseline'];
        if ($baseline !== null) {
            $item = $this->condition($company, $vehicle, $baseline['canonical_condition_item_id'], $snapshot);
            $event = $occurrences->event($company, $vehicle, (int) $item['id'], $baseline['condition_event_id']);
            if ($event === null || $event['created_at'] !== $baseline['recorded_at'] || ($baseline['kind'] === 'observed_existing' && ($event['event_code'] !== 'observed_existing' || $event['occurred_at'] !== $baseline['observed_at']))) {
                throw new InvalidArgumentException('Baseline must match its owned condition event.');
            }
            $member = $baseline['incident_item_id'] === null ? null : $occurrences->membership($company, $vehicle, $baseline['incident_item_id']);
            if ($baseline['incident_item_id'] !== null && ($member === null || (int) $member['vehicle_damage_item_id'] !== (int) $item['id'] || (int) ($member['condition_event_id'] ?? 0) !== (int) $event['id'] || ($member['panel_code'] ?? null) !== $snapshot['panel_code'] || $member['damage_type_code'] !== $snapshot['damage_type_code'])) {
                throw new InvalidArgumentException('Baseline membership must match its exact event and condition.');
            }
            if ($baseline['kind'] === 'observed_existing' && ($member === null || $member['effect_code'] !== 'observed_existing')) {
                throw new InvalidArgumentException('Physical observation baseline requires exact occurrence provenance.');
            }
            $evidence = array_column($baseline['incident_item_id'] === null ? $items->evidence($company, (int) $item['id']) : $occurrences->evidence($company, $vehicle, $baseline['incident_item_id']), null, 'id');
            foreach ($baseline['evidence_ids'] as $id) {
                $row = $evidence[$id] ?? null;
                if ($row === null || $row['created_at'] > $snapshot['snapshot_recorded_at'] || ($comparison['boundary_at'] !== null && $row['created_at'] > $comparison['boundary_at'])
                    || ($baseline['incident_item_id'] !== null && (int) ($row['vehicle_damage_incident_item_id'] ?? 0) !== $baseline['incident_item_id'])
                    || ($baseline['incident_item_id'] === null && ($row['vehicle_damage_incident_item_id'] ?? null) !== null)) {
                    throw new InvalidArgumentException('Selected baseline evidence must belong to its owned prior occurrence.');
                }
            }
        }
        return $json;
    }

    /** @param array<string,mixed> $snapshot @return array<string,mixed> */
    private function condition(int $company, int $vehicle, int $id, array $snapshot): array
    {
        $items = new VehicleDamageRepository($this->db);
        $item = $items->item($company, $vehicle, $id);
        if ($item === null || ($item['panel_code'] ?? null) !== $snapshot['panel_code'] || $item['damage_type_code'] !== $snapshot['damage_type_code']) {
            throw new InvalidArgumentException('Basis condition must match owned panel and damage type.');
        }
        $items->canonicalItem($company, $vehicle, $id);
        return $item;
    }

    /** @return array{trip_id:?int,boundary_kind:string,boundary_at:?string} */
    private function boundary(int $company, int $vehicle, ?int $trip): array
    {
        if ($trip === null) {
            return ['trip_id' => null, 'boundary_kind' => 'unknown', 'boundary_at' => null];
        }
        $row = (new VehicleDamageRepository($this->db))->trip($company, $vehicle, $trip);
        if ($row === null) {
            throw new InvalidArgumentException('Comparison trip must belong to this vehicle and company.');
        }
        $handoff = $this->db->table('trip_movement_events')->where('company_id', $company)->where('fleet_vehicle_id', $vehicle)->where('turo_trip_normalized_id', $trip)
            ->where('event_code', 'actual_handoff')->where('voided_at', null)->orderBy('occurred_at', 'DESC')->orderBy('id', 'DESC')->get(1)->getRowArray();
        return ['trip_id' => $trip, 'boundary_kind' => $handoff !== null ? 'actual_handoff' : ($row['starts_at'] !== null ? 'scheduled_start' : 'unknown'), 'boundary_at' => $handoff['occurred_at'] ?? $row['starts_at']];
    }

    public static function positiveId(mixed $id): int
    {
        if ((! is_int($id) && (! is_string($id) || ! preg_match('/^[1-9][0-9]*$/D', $id))) || (int) $id < 1 || (string) (int) $id !== (string) $id) {
            throw new InvalidArgumentException('Occurrence IDs must be positive supported integers.');
        }
        return (int) $id;
    }

    private static function time(mixed $value, bool $nullable = false): void
    {
        if ($nullable && $value === null) {
            return;
        }
        $date = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('Pacific/Honolulu')) : false;
        if ($date === false || $date->format('Y-m-d H:i:s') !== $value) {
            throw new InvalidArgumentException('Occurrence timestamps require Honolulu DATETIME values.');
        }
    }

    /** @param array<string,mixed> $snapshot */
    private static function validateStructure(array $snapshot): void
    {
        self::keys($snapshot, ['schema_version', 'snapshot_recorded_at', 'panel_code', 'damage_type_code', 'prior_condition', 'prior_occurrence', 'comparison']);
        if ($snapshot['schema_version'] !== self::VERSION || ($snapshot['panel_code'] !== null && (! is_string($snapshot['panel_code']) || ! isset(VehicleDamage::PANELS[$snapshot['panel_code']])))
            || ! is_string($snapshot['damage_type_code']) || ! isset(VehicleDamageService::DAMAGE_TYPES[$snapshot['damage_type_code']])) {
            throw new InvalidArgumentException('Unsupported occurrence basis version or identity.');
        }
        self::time($snapshot['snapshot_recorded_at']);
        $prior = $snapshot['prior_condition'];
        if ($prior !== null) {
            self::keys($prior, ['canonical_condition_item_id', 'latest_condition_event_id', 'status_code', 'severity_code', 'description', 'discovered_at']);
            self::ids($prior, ['canonical_condition_item_id', 'latest_condition_event_id']);
            if (! is_string($prior['status_code']) || ! isset(VehicleDamageService::STATUSES[$prior['status_code']]) || ! is_string($prior['severity_code']) || ! isset(VehicleDamageService::SEVERITIES[$prior['severity_code']])) {
                throw new InvalidArgumentException('Invalid prior physical condition.');
            }
            self::text($prior['description']);
            self::time($prior['discovered_at']);
            if ($prior['discovered_at'] > $snapshot['snapshot_recorded_at']) {
                throw new InvalidArgumentException('Prior condition discovery cannot follow the basis recording.');
            }
        }
        $occurrence = $snapshot['prior_occurrence'];
        if ($occurrence !== null) {
            self::keys($occurrence, ['incident_item_id', 'condition_event_id', 'effect_code', 'note', 'occurred_at', 'discovered_at', 'recorded_at']);
            self::ids($occurrence, ['incident_item_id']);
            self::ids($occurrence, ['condition_event_id'], true);
            if (! is_string($occurrence['effect_code']) || ! isset(VehicleDamage::EFFECTS[$occurrence['effect_code']])) {
                throw new InvalidArgumentException('Invalid prior occurrence effect.');
            }
            self::text($occurrence['note'], true);
            foreach (['occurred_at', 'discovered_at', 'recorded_at'] as $key) {
                self::time($occurrence[$key], $key === 'occurred_at');
            }
            if (($occurrence['occurred_at'] !== null && $occurrence['occurred_at'] > $occurrence['discovered_at']) || $occurrence['discovered_at'] > $occurrence['recorded_at'] || $occurrence['recorded_at'] > $snapshot['snapshot_recorded_at']) {
                throw new InvalidArgumentException('Prior occurrence chronology is inconsistent.');
            }
        }
        $comparison = $snapshot['comparison'];
        self::keys($comparison, ['trip_id', 'boundary_kind', 'boundary_at', 'baseline']);
        self::ids($comparison, ['trip_id'], true);
        self::time($comparison['boundary_at'], true);
        if (! in_array($comparison['boundary_kind'], ['actual_handoff', 'scheduled_start', 'unknown'], true)
            || (($comparison['boundary_kind'] === 'unknown') !== ($comparison['boundary_at'] === null)) || ($comparison['trip_id'] === null && $comparison['boundary_kind'] !== 'unknown')) {
            throw new InvalidArgumentException('Invalid comparison boundary.');
        }
        $baseline = $comparison['baseline'];
        if ($baseline !== null) {
            self::keys($baseline, ['kind', 'canonical_condition_item_id', 'condition_event_id', 'incident_item_id', 'observed_at', 'recorded_at', 'evidence_ids']);
            self::ids($baseline, ['canonical_condition_item_id', 'condition_event_id']);
            self::ids($baseline, ['incident_item_id'], true);
            self::time($baseline['observed_at'], true);
            self::time($baseline['recorded_at']);
            if (! in_array($baseline['kind'], ['observed_existing', 'recorded_condition'], true) || (($baseline['kind'] === 'observed_existing') !== ($baseline['observed_at'] !== null))
                || $baseline['recorded_at'] > $snapshot['snapshot_recorded_at'] || ($baseline['observed_at'] !== null && $baseline['observed_at'] > $snapshot['snapshot_recorded_at'])) {
                throw new InvalidArgumentException('Invalid baseline kind or chronology.');
            }
            if (! is_array($baseline['evidence_ids']) || ! array_is_list($baseline['evidence_ids']) || count($baseline['evidence_ids']) > 24 || count(array_unique($baseline['evidence_ids'], SORT_REGULAR)) !== count($baseline['evidence_ids'])) {
                throw new InvalidArgumentException('Select at most 24 distinct baseline evidence IDs.');
            }
            foreach ($baseline['evidence_ids'] as $id) {
                if (! is_int($id)) {
                    throw new InvalidArgumentException('Snapshot IDs must be JSON integers.');
                }
                self::positiveId($id);
            }
        }
    }

    private static function keys(mixed $value, array $keys): void
    {
        if (! is_array($value) || array_diff($keys, array_keys($value)) !== []) {
            throw new InvalidArgumentException('Occurrence basis structure is incomplete.');
        }
    }

    private static function ids(array $value, array $keys, bool $nullable = false): void
    {
        foreach ($keys as $key) {
            if ($nullable && $value[$key] === null) {
                continue;
            }
            if (! is_int($value[$key])) {
                throw new InvalidArgumentException('Snapshot IDs must be JSON integers.');
            }
            self::positiveId($value[$key]);
        }
    }

    private static function text(mixed $value, bool $nullable = false): void
    {
        if ($nullable && $value === null) {
            return;
        }
        if (! is_string($value) || mb_strlen($value) > 2000) {
            throw new InvalidArgumentException('Occurrence description and note are limited to 2000 characters.');
        }
    }
}
