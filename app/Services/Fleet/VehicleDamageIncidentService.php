<?php

namespace App\Services\Fleet;

use App\Repositories\AuditLogRepository;
use App\Repositories\LookupRepository;
use App\Repositories\VehicleDamageIncidentRepository;
use App\Repositories\VehicleDamageRepository;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Config\VehicleDamage;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class VehicleDamageIncidentService
{
    private BaseConnection $db;
    private VehicleDamageRepository $items;
    private VehicleDamageIncidentRepository $incidents;
    private VehicleDamageService $conditions;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
        $this->items = new VehicleDamageRepository($this->db);
        $this->incidents = new VehicleDamageIncidentRepository($this->db);
        $this->conditions = new VehicleDamageService($this->db);
    }

    /** @return array{success:bool,id?:int,errors:array<string,string>} */
    public function create(int $companyId, int $vehicleId, array $data, int $actor): array
    {
        return $this->transaction($companyId, $vehicleId, $actor, function () use ($companyId, $vehicleId, $data, $actor): int {
            $areas = $data['areas'] ?? [];
            if (! is_array($areas) || count($areas) < 1 || count($areas) > 40) {
                throw new InvalidArgumentException('Record between 1 and 40 damage areas.');
            }
            $values = $this->incidentValues($companyId, $vehicleId, $data, $actor);
            $this->causalMemberships($values['attribution_type'], array_column($areas, 'effect_code'));
            $id = $this->incidents->insert($values);
            foreach ($areas as $area) {
                if (! is_array($area)) {
                    throw new InvalidArgumentException('Invalid damage area.');
                }
                $this->area($companyId, $vehicleId, $id, $area, $actor);
            }
            $this->audit('vehicle_damage_incidents', $id, null, $values, $actor);

            return $id;
        });
    }

    /** Read-only confirmation data for backfilling an original incident, never a new condition. */
    public function historicalOriginalPreview(int $companyId, int $vehicleId, int $itemId): array
    {
        $item = $this->items->item($companyId, $vehicleId, $itemId);
        if ($item === null || ($item['current_condition_item_id'] ?? null) !== null) {
            throw new InvalidArgumentException('Choose an existing canonical condition in this vehicle and company.');
        }
        $created = array_values(array_filter($this->items->events($companyId, $itemId), static fn (array $event): bool => $event['event_code'] === 'created'));
        if (count($created) !== 1 || ! isset(VehicleDamageService::SEVERITIES[$created[0]['new_severity_code']], VehicleDamageService::DAMAGE_TYPES[$item['damage_type_code']])
            || ($item['panel_code'] !== null && ! isset(VehicleDamage::PANELS[$item['panel_code']]))) {
            throw new InvalidArgumentException('The original condition snapshot is unavailable. Review its recorded history first.');
        }

        return [
            'item' => $item,
            'snapshot' => ['panel_code' => $item['panel_code'], 'damage_type_code' => $item['damage_type_code'], 'severity_code' => $created[0]['new_severity_code']],
            'expected_state' => hash('sha256', self::fingerprint($item) . serialize($created[0])),
            'original_incident_id' => $this->incidents->originalIncidentForItem($companyId, $vehicleId, $itemId),
        ];
    }

    /** Historical provenance only: incident and membership audits, no condition/event/evidence writes. */
    public function backfillOriginal(int $companyId, int $vehicleId, int $itemId, array $data, int $actor): array
    {
        return $this->transaction($companyId, $vehicleId, $actor, function () use ($companyId, $vehicleId, $itemId, $data, $actor): int {
            $this->items->lockItem($companyId, $vehicleId, $itemId);
            $preview = $this->historicalOriginalPreview($companyId, $vehicleId, $itemId);
            if (($data['confirmed'] ?? '') !== '1' || ! hash_equals($preview['expected_state'], (string) ($data['expected_state'] ?? ''))) {
                throw new InvalidArgumentException('Explicitly confirm the unchanged historical preview. Reload if the condition changed.');
            }
            if ($preview['original_incident_id'] !== null) {
                throw new InvalidArgumentException('This condition already has an original incident. Use its attribution action instead.');
            }
            if (! empty($data['panel_code']) && $data['panel_code'] !== $preview['snapshot']['panel_code']) {
                throw new InvalidArgumentException('Panel assignment is a separate action; backfill preserves the recorded panel.');
            }
            $reason = $this->text($data['reason'] ?? '', true);
            $tripId = $this->positiveId($data['trip_id'] ?? null);
            if ($tripId === null) {
                throw new InvalidArgumentException('Choose the authoritative historical trip.');
            }
            $values = $this->incidentValues($companyId, $vehicleId, [
                'trip_id' => $tripId, 'attribution_type' => $data['attribution_type'] ?? 'unknown',
                'discovered_at' => $data['discovered_at'] ?? $preview['item']['discovered_at'],
                'occurred_at' => $data['occurred_at'] ?? null, 'overall_note' => $reason,
            ], $actor);
            $id = $this->incidents->insert($values);
            $this->membership($companyId, $id, $itemId, 'new_damage', $preview['snapshot'], $reason, $actor);
            $this->audit('vehicle_damage_incidents', $id, null, $values + ['reason' => $reason, 'historical_backfill' => true, 'existing_condition_id' => $itemId], $actor);

            return $id;
        });
    }

    /** Post-create membership applies its explicitly selected effect transactionally. */
    public function attachArea(int $companyId, int $vehicleId, int $incidentId, array $area, int $actor): array
    {
        return $this->transaction($companyId, $vehicleId, $actor, function () use ($companyId, $vehicleId, $incidentId, $area, $actor): int {
            $incident = $this->requireIncident($companyId, $vehicleId, $incidentId);
            $effects = array_column($this->incidents->memberships($companyId, $vehicleId, $incidentId), 'effect_code');
            $this->causalMemberships($incident['attribution_type'], [...$effects, $area['effect_code'] ?? '']);
            $this->area($companyId, $vehicleId, $incidentId, $area, $actor);

            return $incidentId;
        });
    }

    /** Original discovery fields are never changed when attribution is added/corrected. */
    public function attributeTrip(int $companyId, int $vehicleId, int $incidentId, array $data, int $actor): array
    {
        return $this->transaction($companyId, $vehicleId, $actor, function () use ($companyId, $vehicleId, $incidentId, $data, $actor): int {
            $before = $this->requireIncident($companyId, $vehicleId, $incidentId);
            if (! hash_equals(self::fingerprint($before), (string) ($data['expected_state'] ?? ''))) {
                throw new InvalidArgumentException('Incident changed. Reload before attributing the trip.');
            }
            $reason = $this->text($data['reason'] ?? '', true);
            $tripId = $this->positiveId($data['trip_id'] ?? null);
            if ($tripId === null || $this->items->trip($companyId, $vehicleId, $tripId) === null) {
                throw new InvalidArgumentException('Choose an active normalized trip belonging to this vehicle and company.');
            }
            $type = (string) ($data['attribution_type'] ?? 'unknown');
            $this->attribution($type, $tripId);
            $this->causalMemberships($type, array_column($this->incidents->memberships($companyId, $vehicleId, $incidentId), 'effect_code'));
            $values = ['turo_trip_normalized_id' => $tripId, 'attribution_type' => $type, 'updated_by' => $actor, 'updated_at' => date('Y-m-d H:i:s')];
            // Existing movement/recovery context cannot be silently moved to a different trip.
            if ((! empty($before['trip_movement_event_id']) || ! empty($before['vehicle_recovery_exception_id'])) && (int) $before['turo_trip_normalized_id'] !== $tripId) {
                throw new InvalidArgumentException('This incident has movement/recovery context on its original trip.');
            }
            if ((int) $before['turo_trip_normalized_id'] === $tripId && $before['attribution_type'] === $type) {
                return $incidentId;
            }
            $this->incidents->update($companyId, $vehicleId, $incidentId, $values);
            $this->audit('vehicle_damage_incidents', $incidentId, $before, array_merge($before, $values, ['reason' => $reason]), $actor);

            return $incidentId;
        });
    }

    /** Preview fingerprints enforce complete relationship assumptions after acquiring locks. */
    public function linkHistorical(int $companyId, int $vehicleId, int $sourceId, int $targetId, array $data, int $actor): array
    {
        return $this->transaction($companyId, $vehicleId, $actor, function () use ($companyId, $vehicleId, $sourceId, $targetId, $data, $actor): int {
            if ($sourceId === $targetId || ($data['confirmed'] ?? '') !== '1') {
                throw new InvalidArgumentException('Select different records and explicitly confirm the preview.');
            }
            $reason = $this->text($data['reason'] ?? '', true);
            $this->items->lockItem($companyId, $vehicleId, min($sourceId, $targetId));
            $this->items->lockItem($companyId, $vehicleId, max($sourceId, $targetId));
            $source = $this->items->item($companyId, $vehicleId, $sourceId);
            $target = $this->items->item($companyId, $vehicleId, $targetId);
            if (! hash_equals(self::fingerprint($source), (string) ($data['source_state'] ?? '')) || ! hash_equals(self::fingerprint($target), (string) ($data['target_state'] ?? ''))) {
                throw new InvalidArgumentException('Records changed since preview. Reload and confirm again.');
            }
            if (($source['current_condition_item_id'] ?? null) !== null || ($target['current_condition_item_id'] ?? null) !== null) {
                throw new InvalidArgumentException('Both records must be canonical; chains and cycles are prohibited.');
            }
            $incoming = $this->db->table('vehicle_damage_items')->where('company_id', $companyId)->where('fleet_vehicle_id', $vehicleId)->where('current_condition_item_id', $sourceId)->countAllResults();
            if ($incoming > 0) {
                throw new InvalidArgumentException('The source already has historical records; linking it would create a chain.');
            }
            if (! in_array($target['status_code'], ['open', 'accepted_unrepaired'], true)) {
                throw new InvalidArgumentException('Choose a current canonical target.');
            }
            if ((new \App\Repositories\VehicleDamageRepairRepository($this->db))->hasAnyMembershipForCondition($companyId, $vehicleId, $sourceId)) {
                throw new InvalidArgumentException('This condition has repair/work history and must remain canonical. B2.1 cannot retarget that history. Choose another source or leave the conditions separate.');
            }
            $ranks = array_flip(array_keys(VehicleDamageService::SEVERITIES));
            $severity = $ranks[$source['severity_code']] > $ranks[$target['severity_code']] ? $source['severity_code'] : $target['severity_code'];
            $result = $this->conditions->worsen($companyId, $vehicleId, $targetId, ['severity_code' => $severity, 'note' => $reason], $actor);
            $this->requireSuccess($result);
            $values = ['current_condition_item_id' => $targetId, 'updated_by' => $actor, 'updated_at' => date('Y-m-d H:i:s')];
            $this->items->updateItem($companyId, $vehicleId, $sourceId, $values);
            $this->items->insertEvent([
                'company_id' => $companyId, 'vehicle_damage_item_id' => $sourceId, 'event_code' => 'historical_linked',
                'occurred_at' => date('Y-m-d H:i:s'), 'note' => $reason . ' Canonical condition #' . $targetId . '.',
                'actor_user_id' => $actor, 'created_at' => date('Y-m-d H:i:s'),
                'prior_status_code' => $source['status_code'], 'new_status_code' => $source['status_code'],
                'prior_severity_code' => $source['severity_code'], 'new_severity_code' => $source['severity_code'],
                'prior_description' => $source['description'], 'new_description' => $source['description'],
            ]);
            $tripId = $this->positiveId($source['discovered_turo_trip_normalized_id']);
            if ($tripId !== null && $this->items->trip($companyId, $vehicleId, $tripId) === null) {
                $tripId = null;
            }
            $incident = $this->incidentValues($companyId, $vehicleId, ['discovered_at' => $source['discovered_at'], 'trip_id' => $tripId, 'attribution_type' => 'unknown', 'overall_note' => $reason], $actor);
            $incidentId = $this->incidents->insert($incident);
            // Keep the historical parent as provenance and the target as the affected condition.
            $this->membership($companyId, $incidentId, $sourceId, 'observed_existing', $source, $reason, $actor);
            $this->membership($companyId, $incidentId, $targetId, 'worsened', $source, $reason, $actor);
            $this->audit('vehicle_damage_incidents', $incidentId, null, $incident, $actor);
            $this->audit('vehicle_damage_items', $sourceId, $source, array_merge($source, $values, ['reason' => $reason]), $actor);

            return $incidentId;
        });
    }

    public static function fingerprint(array $record): string
    {
        // Joined display fields are deliberately excluded from concurrency preconditions.
        $fields = ['id', 'company_id', 'fleet_vehicle_id', 'current_condition_item_id', 'panel_code', 'zone_code', 'damage_type_code', 'severity_code', 'status_code', 'description', 'discovered_at', 'updated_by', 'updated_at', 'turo_trip_normalized_id', 'attribution_type'];
        $state = [];
        foreach ($fields as $field) {
            $state[$field] = isset($record[$field]) ? (string) $record[$field] : null;
        }

        return hash('sha256', json_encode($state, JSON_THROW_ON_ERROR));
    }

    private function area(int $companyId, int $vehicleId, int $incidentId, array $area, int $actor): void
    {
        $panel = (string) ($area['panel_code'] ?? '');
        $type = (string) ($area['damage_type_code'] ?? '');
        $severity = (string) ($area['severity_code'] ?? '');
        $effect = (string) ($area['effect_code'] ?? '');
        if (! isset(VehicleDamage::PANELS[$panel], VehicleDamageService::DAMAGE_TYPES[$type], VehicleDamageService::SEVERITIES[$severity], VehicleDamage::EFFECTS[$effect])) {
            throw new InvalidArgumentException('Choose valid panel, type, severity and effect for every area.');
        }
        $note = $this->text($area['note'] ?? '', true);
        $incident = $this->requireIncident($companyId, $vehicleId, $incidentId);
        $context = ['trip_id' => $incident['turo_trip_normalized_id'], 'movement_event_id' => $incident['trip_movement_event_id']];
        if ($effect === 'new_damage') {
            if (! empty($area['vehicle_damage_item_id'])) {
                throw new InvalidArgumentException('New damage cannot select an existing condition.');
            }
            $evidence = array_intersect_key($area, array_flip(['file_id', 'image_id', 'external_reference', 'evidence_label']));
            $result = $this->conditions->create($companyId, $vehicleId, array_merge($evidence, [
                'panel_code' => $panel, 'damage_type_code' => $type, 'severity_code' => $severity,
                'zone_code' => VehicleDamage::zone($panel), 'description' => $note, 'discovered_at' => $incident['discovered_at'],
            ]), $actor, $context + ['recovery_exception_id' => null]);
            $this->requireSuccess($result);
            $itemId = (int) $result['id'];
        } else {
            $itemId = $this->positiveId($area['vehicle_damage_item_id'] ?? null) ?? 0;
            $evidenceItemId = $itemId;
            $this->items->lockItem($companyId, $vehicleId, $itemId);
            $item = $this->items->canonicalItem($companyId, $vehicleId, $itemId);
            if ($item === null || ($effect === 'worsened' && ! in_array($item['status_code'], ['open', 'accepted_unrepaired'], true))) {
                throw new InvalidArgumentException('Existing condition changed or is no longer current. Reload before recording.');
            }
            $itemId = (int) $item['id'];
            $this->items->lockItem($companyId, $vehicleId, $itemId);
            if (array_any($this->incidents->memberships($companyId, $vehicleId, $incidentId), static fn (array $membership): bool => (int) $membership['vehicle_damage_item_id'] === $itemId)) {
                throw new InvalidArgumentException('This condition is already attached to the incident.');
            }
            if (! empty($item['panel_code']) && $item['panel_code'] !== $panel) {
                throw new InvalidArgumentException('The selected panel must match the existing condition.');
            }
            if ($effect === 'observed_existing') {
                if ($severity !== $item['severity_code']) {
                    throw new InvalidArgumentException('Observation severity must match the canonical condition; select worsening to increase it.');
                }
                $result = $this->conditions->observe($companyId, $vehicleId, $itemId, $note, $actor, $context);
            } else {
                $result = $this->conditions->worsen($companyId, $vehicleId, $itemId, ['severity_code' => $severity, 'note' => $note, 'occurred_at' => $incident['occurred_at']], $actor, $context);
            }
            $this->requireSuccess($result);
            if (! empty($area['file_id']) || ! empty($area['image_id']) || ! empty($area['external_reference'])) {
                $this->requireSuccess($this->conditions->attachEvidence($companyId, $vehicleId, $evidenceItemId, $area, $actor));
            }
        }
        $this->membership($companyId, $incidentId, $itemId, $effect, $area, $note, $actor);
    }

    private function membership(int $companyId, int $incidentId, int $itemId, string $effect, array $snapshot, ?string $note, int $actor): void
    {
        $values = [
            'company_id' => $companyId, 'vehicle_damage_incident_id' => $incidentId, 'vehicle_damage_item_id' => $itemId,
            'effect_code' => $effect, 'panel_code' => $snapshot['panel_code'] ?? null,
            'damage_type_code' => $snapshot['damage_type_code'], 'severity_code' => $snapshot['severity_code'],
            'note' => $note, 'created_by' => $actor, 'created_at' => date('Y-m-d H:i:s'),
        ];
        $id = $this->incidents->insertMembership($values);
        $this->audit('vehicle_damage_incident_items', $id, null, $values, $actor);
    }

    private function incidentValues(int $companyId, int $vehicleId, array $data, int $actor): array
    {
        $tripId = $this->positiveId($data['trip_id'] ?? null);
        $eventId = $this->positiveId($data['movement_event_id'] ?? null);
        $exceptionId = $this->positiveId($data['recovery_exception_id'] ?? null);
        if ($tripId !== null && $this->items->trip($companyId, $vehicleId, $tripId) === null) {
            throw new InvalidArgumentException('Trip must be active and belong to this vehicle and company.');
        }
        if ($eventId !== null && ($tripId === null || $this->items->movementEvent($companyId, $vehicleId, $tripId, $eventId) === null)) {
            throw new InvalidArgumentException('Movement must belong to the selected vehicle and trip.');
        }
        if ($exceptionId !== null) {
            $exception = $tripId === null ? null : $this->items->recoveryException($companyId, $vehicleId, $tripId, $exceptionId);
            if ($exception === null) {
                throw new InvalidArgumentException('Recovery exception must belong to the selected vehicle and trip.');
            }
            $eventId = (int) $exception['trip_movement_event_id'];
        }
        $attribution = (string) ($data['attribution_type'] ?? 'unknown');
        $this->attribution($attribution, $tripId);
        $now = date('Y-m-d H:i:s');

        return [
            'company_id' => $companyId, 'fleet_vehicle_id' => $vehicleId, 'turo_trip_normalized_id' => $tripId,
            'trip_movement_event_id' => $eventId, 'vehicle_recovery_exception_id' => $exceptionId,
            'discovered_at' => $this->dateTime((string) ($data['discovered_at'] ?? '')),
            'occurred_at' => empty($data['occurred_at']) ? null : $this->dateTime((string) $data['occurred_at']),
            'attribution_type' => $attribution, 'overall_note' => $this->text($data['overall_note'] ?? '', in_array($attribution, ['suspected_cause', 'operator_attributed_cause'], true)),
            'created_by' => $actor, 'created_at' => $now, 'updated_by' => $actor, 'updated_at' => $now,
        ];
    }

    private function attribution(string $type, ?int $tripId): void
    {
        if (! isset(VehicleDamage::ATTRIBUTIONS[$type]) || ($type !== 'unknown' && $tripId === null)) {
            throw new InvalidArgumentException('Choose valid attribution; attribution to a trip requires a normalized trip.');
        }
    }

    /** Attribution describes the incident; observations retain their non-causal membership effect. */
    private function causalMemberships(string $type, array $effects): void
    {
        if (in_array($type, ['suspected_cause', 'operator_attributed_cause'], true) && array_intersect($effects, ['new_damage', 'worsened']) === []) {
            throw new InvalidArgumentException('An observation-only incident cannot attribute cause. It must include new damage or worsening.');
        }
    }

    private function requireIncident(int $companyId, int $vehicleId, int $id): array
    {
        return $this->incidents->incident($companyId, $vehicleId, $id) ?? throw new InvalidArgumentException('Incident not found for this company and vehicle.');
    }

    private function transaction(int $companyId, int $vehicleId, int $actor, callable $operation): array
    {
        $this->db->transBegin();
        try {
            if ($actor < 1) {
                throw new InvalidArgumentException('An authenticated operator is required.');
            }
            $this->items->lockVehicle($companyId, $vehicleId);
            $id = $operation();
            if (! $this->db->transStatus()) {
                throw new RuntimeException('Incident transaction failed.');
            }
            $this->db->transCommit();

            return ['success' => true, 'id' => $id, 'errors' => []];
        } catch (Throwable $exception) {
            $this->db->transRollback();

            return ['success' => false, 'errors' => ['incident' => $exception->getMessage()]];
        }
    }

    private function requireSuccess(array $result): void
    {
        if (! $result['success']) {
            throw new InvalidArgumentException(implode(' ', $result['errors']));
        }
    }

    private function positiveId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            throw new InvalidArgumentException('Identifiers must be positive whole numbers.');
        }

        return (int) $value;
    }

    private function text(mixed $value, bool $required = false): ?string
    {
        $value = trim((string) $value);
        if (($required && $value === '') || mb_strlen($value) > 2000) {
            throw new InvalidArgumentException('Supply a note/reason of 1 through 2000 characters.');
        }

        return $value === '' ? null : $value;
    }

    private function dateTime(string $value): string
    {
        foreach (['!Y-m-d\TH:i', '!Y-m-d H:i:s'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $value);
            if ($date !== false && $date->format(substr($format, 1)) === $value) {
                return $date->format('Y-m-d H:i:s');
            }
        }

        throw new InvalidArgumentException('Choose a valid date and time.');
    }

    private function audit(string $table, int $id, ?array $before, array $after, int $actor): void
    {
        (new AuditLogRepository($this->db))->record($actor, (new LookupRepository($this->db))->valueId('audit_action', $before === null ? 'created' : 'updated'), $table, $id, $before, $after);
    }
}
