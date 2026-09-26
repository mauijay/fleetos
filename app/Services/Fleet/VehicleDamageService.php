<?php

namespace App\Services\Fleet;

use App\Repositories\AuditLogRepository;
use App\Repositories\LookupRepository;
use App\Repositories\VehicleDamageRepository;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;
use Throwable;

class VehicleDamageService
{
    public const ZONES = [
        'front' => 'Front',
        'rear' => 'Rear',
        'driver_side' => 'Driver side',
        'passenger_side' => 'Passenger side',
        'roof_glass' => 'Roof / glass',
        'wheel_tire' => 'Wheel / tire',
        'interior' => 'Interior',
        'underbody' => 'Underbody',
        'other' => 'Other',
    ];

    public const DAMAGE_TYPES = [
        'dent' => 'Dent',
        'scratch_scuff' => 'Scratch / scuff',
        'paint_chip' => 'Paint chip',
        'crack_broken' => 'Cracked / broken',
        'wheel_curb_rash' => 'Wheel curb rash',
        'glass' => 'Glass',
        'tire' => 'Tire',
        'interior' => 'Interior',
        'missing_broken_part' => 'Missing / broken part',
        'other' => 'Other',
    ];

    public const SEVERITIES = [
        'cosmetic' => 'Cosmetic',
        'moderate' => 'Moderate',
        'severe' => 'Severe',
        'unsafe' => 'Unsafe',
    ];

    public const STATUSES = [
        'open' => 'Open',
        'accepted_unrepaired' => 'Accepted — unrepaired',
        'repaired' => 'Repaired',
        'resolved_other' => 'Resolved — other',
    ];

    private const SEVERITY_RANK = ['cosmetic' => 1, 'moderate' => 2, 'severe' => 3, 'unsafe' => 4];

    private BaseConnection $db;

    public function __construct(
        ?BaseConnection $db = null,
        private readonly ?VehicleDamageRepository $repository = null,
        private readonly ?AuditLogRepository $auditRepository = null,
        private readonly ?LookupRepository $lookupRepository = null,
    ) {
        $this->db = $db ?? Database::connect();
    }

    /** @return array{current:list<array<string,mixed>>,history:list<array<string,mixed>>,has_unsafe:bool,zones:array<string,string>,damage_types:array<string,string>,severities:array<string,string>,statuses:array<string,string>} */
    public function workspace(int $companyId, int $vehicleId): array
    {
        $current = $this->enrich($companyId, $this->repo()->currentForVehicle($companyId, $vehicleId));
        $history = $this->enrich($companyId, $this->repo()->historyForVehicle($companyId, $vehicleId));

        return [
            'current' => $current,
            'history' => $history,
            'has_unsafe' => array_any($current, static fn (array $item): bool => $item['severity_code'] === 'unsafe'),
            'zones' => self::ZONES,
            'damage_types' => self::DAMAGE_TYPES,
            'severities' => self::SEVERITIES,
            'statuses' => self::STATUSES,
        ];
    }

    /** @return list<array<string,mixed>> */
    public function availableDamageExceptions(int $companyId, int $vehicleId, int $tripId): array
    {
        return $this->repo()->availableDamageExceptions($companyId, $vehicleId, $tripId);
    }

    /**
     * @param array{trip_id?:int|null,movement_event_id?:int|null,recovery_exception_id?:int|null} $trustedContext
     * @return array{success:bool,id?:int,errors:array<string,string>}
     */
    public function create(int $companyId, int $vehicleId, array $data, int $actorUserId, array $trustedContext = []): array
    {
        if ($this->repo()->vehicle($companyId, $vehicleId) === null) {
            return $this->failure('vehicle', 'Vehicle not found in the active fleet company.');
        }
        if ($actorUserId < 1) {
            return $this->failure('actor', 'An authenticated operator is required.');
        }
        $errors = $this->validateCreate($data);
        if ($errors !== []) {
            return ['success' => false, 'errors' => $errors];
        }

        try {
            $context = $this->resolveContext($companyId, $vehicleId, $data, $trustedContext);
        } catch (\InvalidArgumentException $exception) {
            return $this->failure('context', $exception->getMessage());
        }

        $now = date('Y-m-d H:i:s');
        $values = [
            'company_id' => $companyId,
            'fleet_vehicle_id' => $vehicleId,
            'discovered_turo_trip_normalized_id' => $context['trip_id'],
            'discovered_trip_movement_event_id' => $context['movement_event_id'],
            'vehicle_recovery_exception_id' => $context['recovery_exception_id'],
            'damage_claim_id' => $context['claim_id'],
            'zone_code' => trim((string) $data['zone_code']),
            'damage_type_code' => trim((string) $data['damage_type_code']),
            'description' => trim((string) $data['description']),
            'severity_code' => trim((string) $data['severity_code']),
            'status_code' => 'open',
            'discovered_at' => $this->dateTime((string) $data['discovered_at']),
            'created_by' => $actorUserId,
            'created_at' => $now,
            'updated_by' => $actorUserId,
            'updated_at' => $now,
        ];

        $this->db->transBegin();
        try {
            $id = $this->repo()->insertItem($values);
            $this->repo()->insertEvent($this->eventValues(
                $companyId,
                $id,
                'created',
                $values['discovered_at'],
                $actorUserId,
                $context['trip_id'],
                $context['movement_event_id'],
                null,
                $values,
                null,
            ));
            $this->addEvidence($companyId, $vehicleId, $id, $data, $actorUserId, $now);
            $this->audit('created', $id, null, $values, $actorUserId);
            if ($this->db->transStatus() === false) {
                throw new \RuntimeException('Damage item transaction failed.');
            }
            $this->db->transCommit();

            return ['success' => true, 'id' => $id, 'errors' => []];
        } catch (Throwable $exception) {
            $this->db->transRollback();

            return $this->failure('database', $exception->getMessage());
        }
    }

    /** @return array{success:bool,id?:int,errors:array<string,string>} */
    public function correctDetails(int $companyId, int $vehicleId, int $itemId, array $data, int $actorUserId): array
    {
        $item = $this->repo()->item($companyId, $vehicleId, $itemId);
        if ($item === null) {
            return $this->failure('item', 'Damage item not found in the active fleet company.');
        }
        $description = trim((string) ($data['description'] ?? ''));
        $zone = trim((string) ($data['zone_code'] ?? ''));
        $type = trim((string) ($data['damage_type_code'] ?? ''));
        $note = trim((string) ($data['note'] ?? ''));
        $errors = [];
        if (! isset(self::ZONES[$zone])) {
            $errors['zone_code'] = 'Choose a valid vehicle zone.';
        }
        if (! isset(self::DAMAGE_TYPES[$type])) {
            $errors['damage_type_code'] = 'Choose a valid damage type.';
        }
        if ($description === '' || mb_strlen($description) > 2000) {
            $errors['description'] = 'Describe the damage in 1 through 2000 characters.';
        }
        if ($note === '' || mb_strlen($note) > 2000) {
            $errors['note'] = 'Explain the correction in 1 through 2000 characters.';
        }
        if ($errors !== []) {
            return ['success' => false, 'errors' => $errors];
        }

        $values = ['zone_code' => $zone, 'damage_type_code' => $type, 'description' => $description, 'updated_by' => $actorUserId, 'updated_at' => date('Y-m-d H:i:s')];

        return $this->updateWithEvent($item, $values, 'detail_corrected', $note, $actorUserId);
    }

    /** @return array{success:bool,id?:int,errors:array<string,string>} */
    public function changeSeverity(int $companyId, int $vehicleId, int $itemId, string $severity, string $note, int $actorUserId): array
    {
        $item = $this->repo()->item($companyId, $vehicleId, $itemId);
        if ($item === null) {
            return $this->failure('item', 'Damage item not found in the active fleet company.');
        }
        $severity = trim($severity);
        $note = trim($note);
        if (! isset(self::SEVERITIES[$severity])) {
            return $this->failure('severity_code', 'Choose a valid severity.');
        }
        if ($note === '' || mb_strlen($note) > 2000) {
            return $this->failure('note', 'Explain the severity change in 1 through 2000 characters.');
        }

        return $this->updateWithEvent(
            $item,
            ['severity_code' => $severity, 'updated_by' => $actorUserId, 'updated_at' => date('Y-m-d H:i:s')],
            'severity_changed',
            $note,
            $actorUserId,
        );
    }

    /**
     * @param array{trip_id?:int|null,movement_event_id?:int|null} $trustedContext
     * @return array{success:bool,id?:int,errors:array<string,string>}
     */
    public function worsen(int $companyId, int $vehicleId, int $itemId, array $data, int $actorUserId, array $trustedContext = []): array
    {
        $item = $this->repo()->item($companyId, $vehicleId, $itemId);
        if ($item === null || ! in_array($item['status_code'], ['open', 'accepted_unrepaired'], true)) {
            return $this->failure('item', 'Only current physical damage can be marked worsened.');
        }
        $severity = trim((string) ($data['severity_code'] ?? $item['severity_code']));
        $note = trim((string) ($data['note'] ?? ''));
        if (! isset(self::SEVERITIES[$severity])) {
            return $this->failure('severity_code', 'Choose a valid severity.');
        }
        if (self::SEVERITY_RANK[$severity] < self::SEVERITY_RANK[(string) $item['severity_code']]) {
            return $this->failure('severity_code', 'A worsening event cannot lower severity.');
        }
        if ($note === '' || mb_strlen($note) > 2000) {
            return $this->failure('note', 'Describe how the damage worsened in 1 through 2000 characters.');
        }
        try {
            $context = $this->resolveSourceContext($companyId, $vehicleId, $trustedContext);
        } catch (\InvalidArgumentException $exception) {
            return $this->failure('context', $exception->getMessage());
        }
        $occurredAt = isset($data['occurred_at']) && trim((string) $data['occurred_at']) !== ''
            ? $this->dateTime((string) $data['occurred_at'])
            : date('Y-m-d H:i:s');
        $values = ['severity_code' => $severity, 'updated_by' => $actorUserId, 'updated_at' => date('Y-m-d H:i:s')];

        return $this->updateWithEvent($item, $values, 'worsened', $note, $actorUserId, $context, $occurredAt);
    }

    /** @return array{success:bool,id?:int,errors:array<string,string>} */
    public function transitionStatus(int $companyId, int $vehicleId, int $itemId, string $status, string $note, int $actorUserId): array
    {
        $item = $this->repo()->item($companyId, $vehicleId, $itemId);
        if ($item === null) {
            return $this->failure('item', 'Damage item not found in the active fleet company.');
        }
        $allowed = [
            'open' => ['accepted_unrepaired', 'repaired', 'resolved_other'],
            'accepted_unrepaired' => ['repaired', 'resolved_other'],
            'repaired' => [],
            'resolved_other' => [],
        ];
        if (! isset(self::STATUSES[$status]) || ! in_array($status, $allowed[(string) $item['status_code']] ?? [], true)) {
            return $this->failure('status_code', 'Choose a valid next damage status.');
        }
        $note = trim($note);
        if ($note === '' || mb_strlen($note) > 2000) {
            return $this->failure('note', 'Record a status reason in 1 through 2000 characters.');
        }
        $now = date('Y-m-d H:i:s');
        $values = [
            'status_code' => $status,
            'updated_by' => $actorUserId,
            'updated_at' => $now,
            'resolved_by' => in_array($status, ['repaired', 'resolved_other'], true) ? $actorUserId : null,
            'resolved_at' => in_array($status, ['repaired', 'resolved_other'], true) ? $now : null,
            'resolution_note' => in_array($status, ['repaired', 'resolved_other'], true) ? $note : null,
        ];

        return $this->updateWithEvent($item, $values, $status, $note, $actorUserId);
    }

    /** @return list<array<string,mixed>> */
    private function enrich(int $companyId, array $items): array
    {
        foreach ($items as &$item) {
            $item['zone_label'] = self::ZONES[$item['zone_code']] ?? ucfirst(str_replace('_', ' ', (string) $item['zone_code']));
            $item['damage_type_label'] = self::DAMAGE_TYPES[$item['damage_type_code']] ?? ucfirst(str_replace('_', ' ', (string) $item['damage_type_code']));
            $item['severity_label'] = self::SEVERITIES[$item['severity_code']] ?? ucfirst((string) $item['severity_code']);
            $item['status_label'] = self::STATUSES[$item['status_code']] ?? ucfirst(str_replace('_', ' ', (string) $item['status_code']));
            $item['events'] = $this->repo()->events($companyId, (int) $item['id']);
            $item['evidence'] = $this->repo()->evidence($companyId, (int) $item['id']);
        }
        unset($item);

        return $items;
    }

    /** @return array<string,string> */
    private function validateCreate(array $data): array
    {
        $errors = [];
        if (! isset(self::ZONES[trim((string) ($data['zone_code'] ?? ''))])) {
            $errors['zone_code'] = 'Choose a valid vehicle zone.';
        }
        if (! isset(self::DAMAGE_TYPES[trim((string) ($data['damage_type_code'] ?? ''))])) {
            $errors['damage_type_code'] = 'Choose a valid damage type.';
        }
        if (! isset(self::SEVERITIES[trim((string) ($data['severity_code'] ?? ''))])) {
            $errors['severity_code'] = 'Choose a valid severity.';
        }
        $description = trim((string) ($data['description'] ?? ''));
        if ($description === '' || mb_strlen($description) > 2000) {
            $errors['description'] = 'Describe the damage in 1 through 2000 characters.';
        }
        try {
            $this->dateTime((string) ($data['discovered_at'] ?? ''));
        } catch (\InvalidArgumentException $exception) {
            $errors['discovered_at'] = $exception->getMessage();
        }
        if (isset($data['external_reference']) && mb_strlen(trim((string) $data['external_reference'])) > 500) {
            $errors['external_reference'] = 'Evidence reference must be 500 characters or fewer.';
        }

        return $errors;
    }

    /**
     * @param array{trip_id?:int|null,movement_event_id?:int|null,recovery_exception_id?:int|null} $trustedContext
     * @return array{trip_id:?int,movement_event_id:?int,recovery_exception_id:?int,claim_id:?int}
     */
    private function resolveContext(int $companyId, int $vehicleId, array $data, array $trustedContext): array
    {
        $tripId = $this->nullableId(array_key_exists('trip_id', $trustedContext) ? $trustedContext['trip_id'] : ($data['trip_id'] ?? null));
        $eventId = $this->nullableId(array_key_exists('movement_event_id', $trustedContext) ? $trustedContext['movement_event_id'] : ($data['movement_event_id'] ?? null));
        $exceptionId = $this->nullableId(array_key_exists('recovery_exception_id', $trustedContext) ? $trustedContext['recovery_exception_id'] : ($data['recovery_exception_id'] ?? null));
        $claimId = $this->nullableId($data['damage_claim_id'] ?? null);

        if ($tripId !== null && $this->repo()->trip($companyId, $vehicleId, $tripId) === null) {
            throw new \InvalidArgumentException('The source trip does not belong to this vehicle and company.');
        }
        if ($eventId !== null) {
            if ($tripId === null || $this->repo()->movementEvent($companyId, $vehicleId, $tripId, $eventId) === null) {
                throw new \InvalidArgumentException('The source movement event does not belong to this vehicle, trip, and company.');
            }
        }
        if ($exceptionId !== null) {
            if ($tripId === null) {
                throw new \InvalidArgumentException('A recovery exception requires its source trip.');
            }
            $exception = $this->repo()->recoveryException($companyId, $vehicleId, $tripId, $exceptionId);
            if ($exception === null) {
                throw new \InvalidArgumentException('The damage recovery exception does not belong to this vehicle, trip, and company.');
            }
            $eventId = (int) $exception['trip_movement_event_id'];
        }
        if ($claimId !== null && $this->repo()->claim($companyId, $vehicleId, $claimId) === null) {
            throw new \InvalidArgumentException('The damage claim does not belong to this vehicle and company.');
        }

        return ['trip_id' => $tripId, 'movement_event_id' => $eventId, 'recovery_exception_id' => $exceptionId, 'claim_id' => $claimId];
    }

    /**
     * @param array{trip_id?:int|null,movement_event_id?:int|null} $trustedContext
     * @return array{trip_id:?int,movement_event_id:?int}
     */
    private function resolveSourceContext(int $companyId, int $vehicleId, array $trustedContext): array
    {
        $tripId = $this->nullableId($trustedContext['trip_id'] ?? null);
        $eventId = $this->nullableId($trustedContext['movement_event_id'] ?? null);
        if ($tripId !== null && $this->repo()->trip($companyId, $vehicleId, $tripId) === null) {
            throw new \InvalidArgumentException('The source trip does not belong to this vehicle and company.');
        }
        if ($eventId !== null && ($tripId === null || $this->repo()->movementEvent($companyId, $vehicleId, $tripId, $eventId) === null)) {
            throw new \InvalidArgumentException('The source movement event does not belong to this vehicle, trip, and company.');
        }

        return ['trip_id' => $tripId, 'movement_event_id' => $eventId];
    }

    /** @param array<string,mixed> $item @param array<string,mixed> $values @param array{trip_id:?int,movement_event_id:?int}|null $context */
    private function updateWithEvent(array $item, array $values, string $eventCode, string $note, int $actorUserId, ?array $context = null, ?string $occurredAt = null): array
    {
        if ($actorUserId < 1) {
            return $this->failure('actor', 'An authenticated operator is required.');
        }
        $context ??= ['trip_id' => null, 'movement_event_id' => null];
        $new = array_merge($item, $values);
        $this->db->transBegin();
        try {
            if (! $this->repo()->updateItem((int) $item['company_id'], (int) $item['fleet_vehicle_id'], (int) $item['id'], $values)) {
                throw new \RuntimeException('Damage item changed before it could be updated.');
            }
            $this->repo()->insertEvent($this->eventValues(
                (int) $item['company_id'],
                (int) $item['id'],
                $eventCode,
                $occurredAt ?? date('Y-m-d H:i:s'),
                $actorUserId,
                $context['trip_id'],
                $context['movement_event_id'],
                $item,
                $new,
                $note,
            ));
            $this->audit('updated', (int) $item['id'], $item, $new, $actorUserId);
            if ($this->db->transStatus() === false) {
                throw new \RuntimeException('Damage item update transaction failed.');
            }
            $this->db->transCommit();

            return ['success' => true, 'id' => (int) $item['id'], 'errors' => []];
        } catch (Throwable $exception) {
            $this->db->transRollback();

            return $this->failure('database', $exception->getMessage());
        }
    }

    /** @param array<string,mixed>|null $old @param array<string,mixed> $new @return array<string,mixed> */
    private function eventValues(int $companyId, int $itemId, string $eventCode, string $occurredAt, int $actorUserId, ?int $tripId, ?int $movementEventId, ?array $old, array $new, ?string $note): array
    {
        return [
            'company_id' => $companyId,
            'vehicle_damage_item_id' => $itemId,
            'event_code' => $eventCode,
            'source_turo_trip_normalized_id' => $tripId,
            'source_trip_movement_event_id' => $movementEventId,
            'occurred_at' => $occurredAt,
            'prior_status_code' => $old['status_code'] ?? null,
            'new_status_code' => $new['status_code'] ?? null,
            'prior_severity_code' => $old['severity_code'] ?? null,
            'new_severity_code' => $new['severity_code'] ?? null,
            'prior_description' => $old['description'] ?? null,
            'new_description' => $new['description'] ?? null,
            'note' => $note,
            'actor_user_id' => $actorUserId,
            'created_at' => date('Y-m-d H:i:s'),
        ];
    }

    private function addEvidence(int $companyId, int $vehicleId, int $itemId, array $data, int $actorUserId, string $now): void
    {
        $fileId = $this->nullableId($data['file_id'] ?? null);
        $imageId = $this->nullableId($data['image_id'] ?? null);
        $external = trim((string) ($data['external_reference'] ?? ''));
        $provided = ($fileId === null ? 0 : 1) + ($imageId === null ? 0 : 1) + ($external === '' ? 0 : 1);
        if ($provided === 0) {
            return;
        }
        if ($provided > 1) {
            throw new \InvalidArgumentException('Add one evidence reference at a time.');
        }
        if ($fileId !== null && ! $this->repo()->fileBelongsToVehicle($companyId, $vehicleId, $fileId)) {
            throw new \InvalidArgumentException('The evidence file does not belong to this vehicle and company.');
        }
        if ($imageId !== null && ! $this->repo()->imageBelongsToVehicle($companyId, $vehicleId, $imageId)) {
            throw new \InvalidArgumentException('The evidence image does not belong to this vehicle and company.');
        }
        $this->repo()->insertEvidence([
            'company_id' => $companyId,
            'vehicle_damage_item_id' => $itemId,
            'file_id' => $fileId,
            'image_id' => $imageId,
            'external_reference' => $external === '' ? null : $external,
            'label' => $this->nullableText($data['evidence_label'] ?? null, 190),
            'created_by' => $actorUserId,
            'created_at' => $now,
        ]);
    }

    private function dateTime(string $value): string
    {
        $value = trim($value);
        foreach (['!Y-m-d\TH:i', '!Y-m-d H:i:s'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $value);
            if ($date !== false && $date->format(substr($format, 1)) === $value) {
                return $date->format('Y-m-d H:i:s');
            }
        }

        throw new \InvalidArgumentException('Choose a valid discovery date and time.');
    }

    private function nullableId(mixed $value): ?int
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        if (filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            throw new \InvalidArgumentException('Related record identifiers must be positive whole numbers.');
        }

        return (int) $value;
    }

    private function nullableText(mixed $value, int $maximum): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value) > $maximum) {
            throw new \InvalidArgumentException('Evidence label is too long.');
        }

        return $value;
    }

    private function audit(string $action, int $id, ?array $old, array $new, int $actorUserId): void
    {
        $this->audits()->record($actorUserId, $this->lookups()->valueId('audit_action', $action), 'vehicle_damage_items', $id, $old, $new);
    }

    /** @return array{success:false,errors:array<string,string>} */
    private function failure(string $field, string $message): array
    {
        return ['success' => false, 'errors' => [$field => $message]];
    }

    private function repo(): VehicleDamageRepository
    {
        return $this->repository ?? new VehicleDamageRepository($this->db);
    }

    private function audits(): AuditLogRepository
    {
        return $this->auditRepository ?? new AuditLogRepository($this->db);
    }

    private function lookups(): LookupRepository
    {
        return $this->lookupRepository ?? new LookupRepository($this->db);
    }
}
