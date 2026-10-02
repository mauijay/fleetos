<?php

namespace App\Services\Fleet;

use App\Repositories\AuditLogRepository;
use App\Repositories\FleetExtraRepository;
use App\Repositories\LookupRepository;
use CodeIgniter\Exceptions\PageNotFoundException;
use InvalidArgumentException;

class FleetExtraService
{
    public const FULFILLMENT_TYPES = ['none', 'informational', 'pack', 'install', 'configure', 'logistics'];
    public const FULFILLMENT_PHASES = ['preparation', 'pickup', 'return', 'entire_trip'];

    public function __construct(
        private readonly FleetExtraRepository $extras = new FleetExtraRepository(),
        private readonly AuditLogRepository $audit = new AuditLogRepository(),
        private readonly LookupRepository $lookups = new LookupRepository(),
        private readonly ?TripExtraFulfillmentService $fulfillments = null,
    ) {
    }

    /** @return array<string,mixed> */
    public function workspace(int $companyId, ?string $reservationId = null): array
    {
        if ($reservationId !== null && preg_match('/^\d{1,80}$/', $reservationId) !== 1) {
            throw new InvalidArgumentException('Enter a numeric Turo reservation ID.');
        }
        $candidates = $this->extras->refreshCandidates($companyId, $reservationId);
        $verification = $this->verificationForTrips($companyId, array_map('intval', array_column($candidates, 'trip_id')));
        foreach ($candidates as &$candidate) {
            $candidate['verification'] = $verification[(int) $candidate['trip_id']] ?? $this->unverified();
        }
        unset($candidate);

        return [
            'catalog' => $this->extras->catalog($companyId),
            'mappings' => $this->extras->mappings($companyId),
            'unmapped' => $this->extras->unmappedSources($companyId),
            'counts' => $this->extras->counts($companyId),
            'reservation_ids' => array_values(array_unique(array_column($candidates, 'reservation_id'))),
            'refresh_candidates' => $candidates,
            'reservation_lookup' => $reservationId,
        ];
    }

    /** @param list<int> $tripIds @return array<int, array<string, mixed>> */
    public function verificationForTrips(int $companyId, array $tripIds): array
    {
        $trips = $this->extras->tripsForVerification($companyId, $tripIds);
        $reservationIds = array_values(array_unique(array_column($trips, 'reservation_id')));
        $complete = $issues = [];
        foreach ($this->extras->verificationObservations($companyId, $reservationIds) as $row) {
            $reservationId = (string) $row['turo_reservation_id'];
            if ((bool) $row['snapshot_complete']) {
                $complete[$reservationId] = $row;
            } else {
                $issues[$reservationId] = ['observed_at' => (string) $row['observed_at'], 'message' => 'Latest observation was incomplete; complete verification is required.'];
            }
        }
        foreach ($this->extras->verificationFailures($companyId, $reservationIds) as $row) {
            $payload = json_decode((string) $row['raw_payload'], true);
            $reservationId = (string) ($payload['reservation_id'] ?? '');
            $observedAt = (string) ($payload['observed_at'] ?? '');
            if ($observedAt >= ($issues[$reservationId]['observed_at'] ?? '')) {
                $issues[$reservationId] = ['observed_at' => $observedAt, 'message' => $row['error_code'] === 'extras_observation_conflict'
                    ? 'Conflicting observations at the same time; export a fresh snapshot.'
                    : 'Latest Extras verification failed; export a fresh snapshot.'];
            }
        }
        $result = [];
        foreach ($trips as $trip) {
            $reservationId = (string) $trip['reservation_id'];
            $state = $this->unverified();
            if (isset($complete[$reservationId])) {
                $snapshot = $complete[$reservationId];
                $payload = json_decode((string) $snapshot['source_payload'], true);
                $count = count($payload['extras'] ?? []);
                $label = (new \DateTimeImmutable((string) $snapshot['observed_at'], new \DateTimeZone('UTC')))
                    ->setTimezone(new \DateTimeZone((new \Config\App())->appTimezone))->format('M j, Y g:i:s A T');
                $state = [
                    'state' => $count === 0 ? 'complete_empty' : 'complete_nonempty',
                    'observed_at' => (string) $snapshot['observed_at'],
                    'verified_label' => $label,
                    'summary' => $count === 0 ? 'No Extras observed as of ' . $label : 'Extras verified ' . $label,
                    'issue' => null,
                ];
            }
            if (isset($issues[$reservationId]) && $issues[$reservationId]['observed_at'] >= ($state['observed_at'] ?? '')) {
                $state['issue'] = $issues[$reservationId]['message'];
            }
            $result[(int) $trip['trip_id']] = $state;
        }

        return $result;
    }

    public function reconcileTripLinks(int $companyId, int $actorUserId): void
    {
        $this->requireContext($companyId, $actorUserId);
        $trips = $this->extras->tripsByReservationIds($companyId, $this->extras->unmatchedReservationIds($companyId));
        foreach (array_unique(array_column($trips, 'id')) as $tripId) {
            $this->fulfillments?->reconcileForTrip($companyId, (int) $tripId, $actorUserId);
        }
    }

    /** @return array<string, mixed> */
    private function unverified(): array
    {
        return ['state' => 'never', 'observed_at' => null, 'verified_label' => null, 'summary' => 'Extras not verified for this reservation.', 'issue' => null];
    }

    public function createExtra(int $companyId, array $input, int $actorUserId): int
    {
        $this->requireContext($companyId, $actorUserId);
        $data = $this->extraData($input);
        if ($this->extras->extraByCode($companyId, $data['code']) !== null) {
            throw new InvalidArgumentException('That canonical Extra code already exists for this company.');
        }
        $now = date('Y-m-d H:i:s');

        return $this->extras->transaction(function () use ($companyId, $actorUserId, $data, $now): int {
            $id = $this->extras->createExtra(array_merge($data, [
                'company_id' => $companyId,
                'created_by_user_id' => $actorUserId,
                'updated_by_user_id' => $actorUserId,
                'created_at' => $now,
                'updated_at' => $now,
            ]));
            $this->audit->record($actorUserId, $this->lookups->valueId('audit_action', 'created'), 'fleet_extras', $id, null, array_merge($data, ['company_id' => $companyId]));

            return $id;
        });
    }

    public function updateExtra(int $companyId, int $extraId, array $input, int $actorUserId): void
    {
        $this->requireContext($companyId, $actorUserId);
        $existing = $this->extras->extra($companyId, $extraId);
        if ($existing === null) {
            throw PageNotFoundException::forPageNotFound();
        }
        $data = $this->extraData($input);
        if ($data['code'] !== $existing['code'] && $this->extras->extraHasActivity($companyId, $extraId)) {
            throw new InvalidArgumentException('Canonical Extra code cannot change after source activity exists.');
        }
        $duplicate = $this->extras->extraByCode($companyId, $data['code']);
        if ($duplicate !== null && (int) $duplicate['id'] !== $extraId) {
            throw new InvalidArgumentException('That canonical Extra code already exists for this company.');
        }
        $data['updated_by_user_id'] = $actorUserId;
        $data['updated_at'] = date('Y-m-d H:i:s');
        $this->extras->transaction(function () use ($companyId, $extraId, $actorUserId, $existing, $data): void {
            $this->extras->updateExtra($companyId, $extraId, $data);
            $this->audit->record($actorUserId, $this->lookups->valueId('audit_action', 'updated'), 'fleet_extras', $extraId, $existing, array_merge($existing, $data));
        });
        $this->fulfillments?->reconcileForExtra($companyId, $extraId, $actorUserId);
    }

    public function mapSource(int $companyId, string $sourceExtraId, int $fleetExtraId, ?string $reason, int $actorUserId): int
    {
        $this->requireContext($companyId, $actorUserId);
        $sourceExtraId = trim($sourceExtraId);
        if ($sourceExtraId === '' || mb_strlen($sourceExtraId) > 120) {
            throw new InvalidArgumentException('Choose a valid observed Turo Extra ID.');
        }
        $target = $this->extras->extra($companyId, $fleetExtraId);
        $observation = $this->extras->latestSourceObservation($companyId, $sourceExtraId);
        if ($target === null || $observation === null) {
            throw PageNotFoundException::forPageNotFound();
        }
        $existing = $this->extras->mapping($companyId, 'turo', $sourceExtraId);
        $now = date('Y-m-d H:i:s');
        if ($existing !== null && (int) $existing['fleet_extra_id'] !== $fleetExtraId && mb_strlen(trim((string) $reason)) < 8) {
            throw new InvalidArgumentException('A remap reason of at least 8 characters is required.');
        }
        $data = [
            'fleet_extra_id' => $fleetExtraId,
            'source_type' => $observation['source_type'],
            'latest_source_label' => $observation['source_label'],
            'latest_source_description' => $observation['source_description'],
            'last_seen_at' => $observation['last_observed_at'],
            'last_change_reason' => trim((string) $reason) === '' ? ($existing['last_change_reason'] ?? null) : trim((string) $reason),
            'updated_by_user_id' => $actorUserId,
            'updated_at' => $now,
        ];

        $mappingId = $this->extras->transaction(function () use ($companyId, $sourceExtraId, $actorUserId, $observation, $existing, $data, $now): int {
            if ($existing === null) {
                $id = $this->extras->createMapping(array_merge($data, [
                    'company_id' => $companyId,
                    'source_system' => 'turo',
                    'source_extra_id' => $sourceExtraId,
                    'first_seen_at' => $observation['first_observed_at'],
                    'created_by_user_id' => $actorUserId,
                    'created_at' => $now,
                ]));
                $this->audit->record($actorUserId, $this->lookups->valueId('audit_action', 'created'), 'fleet_extra_source_mappings', $id, null, array_merge($data, ['company_id' => $companyId, 'source_extra_id' => $sourceExtraId]));

                return $id;
            }
            $id = (int) $existing['id'];
            $this->extras->updateMapping($companyId, $id, $data);
            $this->audit->record($actorUserId, $this->lookups->valueId('audit_action', 'updated'), 'fleet_extra_source_mappings', $id, $existing, array_merge($existing, $data));

            return $id;
        });
        $this->fulfillments?->reconcileForSource($companyId, $sourceExtraId, $actorUserId);

        return $mappingId;
    }

    /** @return array{extra_id:int,mapping_id:int} */
    public function createAndMap(int $companyId, string $sourceExtraId, array $extraInput, int $actorUserId): array
    {
        if ($this->extras->latestSourceObservation($companyId, trim($sourceExtraId)) === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        return $this->extras->transaction(function () use ($companyId, $sourceExtraId, $extraInput, $actorUserId): array {
            $extraId = $this->createExtra($companyId, $extraInput, $actorUserId);
            $mappingId = $this->mapSource($companyId, $sourceExtraId, $extraId, 'Created from newly observed Turo Extra', $actorUserId);

            return ['extra_id' => $extraId, 'mapping_id' => $mappingId];
        });
    }

    /** @return array<string, mixed> */
    private function extraData(array $input): array
    {
        $code = strtolower(trim((string) ($input['code'] ?? '')));
        $displayName = trim((string) ($input['display_name'] ?? ''));
        if (preg_match('/^[a-z][a-z0-9_]{1,79}$/', $code) !== 1) {
            throw new InvalidArgumentException('Code must start with a letter and use lowercase letters, numbers, or underscores.');
        }
        if ($displayName === '' || mb_strlen($displayName) > 190) {
            throw new InvalidArgumentException('Display name is required and must be 190 characters or fewer.');
        }
        $sortOrder = filter_var($input['sort_order'] ?? 0, FILTER_VALIDATE_INT);
        if ($sortOrder === false || $sortOrder < 0 || $sortOrder > 100000) {
            throw new InvalidArgumentException('Sort order must be between 0 and 100000.');
        }
        $notes = trim((string) ($input['notes'] ?? ''));
        if (mb_strlen($notes) > 4000) {
            throw new InvalidArgumentException('Notes must be 4000 characters or fewer.');
        }
        $fulfillmentType = strtolower(trim((string) ($input['fulfillment_type'] ?? 'none')));
        $phase = strtolower(trim((string) ($input['fulfillment_phase'] ?? '')));
        $actionLabel = trim((string) ($input['default_action_label'] ?? ''));
        $requiresConfirmation = $this->activeValue($input['requires_operator_confirmation'] ?? null);
        $readinessBlocking = $this->activeValue($input['readiness_blocking'] ?? null);
        if (! in_array($fulfillmentType, self::FULFILLMENT_TYPES, true)) {
            throw new InvalidArgumentException('Choose a valid fulfillment type.');
        }
        if ($actionLabel !== '' && mb_strlen($actionLabel) > 190) {
            throw new InvalidArgumentException('Default action label must be 190 characters or fewer.');
        }
        if (in_array($fulfillmentType, TripExtraFulfillmentService::ACTIONABLE_TYPES, true)) {
            if (! in_array($phase, self::FULFILLMENT_PHASES, true)) {
                throw new InvalidArgumentException('Actionable Extras require a valid fulfillment phase.');
            }
            if ($actionLabel === '') {
                throw new InvalidArgumentException('Actionable Extras require an operator action label.');
            }
            if ($requiresConfirmation !== 1) {
                throw new InvalidArgumentException('Actionable Extras require operator confirmation.');
            }
        } elseif ($fulfillmentType === 'informational') {
            if ($phase !== '' && ! in_array($phase, self::FULFILLMENT_PHASES, true)) {
                throw new InvalidArgumentException('Choose a valid fulfillment phase.');
            }
            $actionLabel = '';
            $requiresConfirmation = 0;
            $readinessBlocking = 0;
        } else {
            $phase = '';
            $actionLabel = '';
            $requiresConfirmation = 0;
            $readinessBlocking = 0;
        }
        if ($readinessBlocking === 1 && $requiresConfirmation !== 1) {
            throw new InvalidArgumentException('Readiness-blocking Extras must require operator confirmation.');
        }

        $data = [
            'code' => $code,
            'display_name' => $displayName,
            'active' => $this->activeValue($input['active'] ?? null),
            'sort_order' => $sortOrder,
            'notes' => $notes === '' ? null : $notes,
        ];
        if ($this->extras->supportsFulfillmentConfiguration()) {
            $data = array_merge($data, [
                'fulfillment_type' => $fulfillmentType,
                'requires_operator_confirmation' => $requiresConfirmation,
                'readiness_blocking' => $readinessBlocking,
                'default_action_label' => $actionLabel === '' ? null : $actionLabel,
                'fulfillment_phase' => $phase === '' ? null : $phase,
            ]);
        }

        return $data;
    }

    private function requireContext(int $companyId, int $actorUserId): void
    {
        if ($companyId < 1 || $actorUserId < 1) {
            throw new InvalidArgumentException('An active company and authenticated operator are required.');
        }
    }

    private function activeValue(mixed $value): int
    {
        if (is_array($value)) {
            return in_array('1', array_map('strval', $value), true) ? 1 : 0;
        }

        return $value !== null && (string) $value !== '0' ? 1 : 0;
    }
}
