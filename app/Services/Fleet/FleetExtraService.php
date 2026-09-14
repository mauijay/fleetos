<?php

namespace App\Services\Fleet;

use App\Repositories\AuditLogRepository;
use App\Repositories\FleetExtraRepository;
use App\Repositories\LookupRepository;
use CodeIgniter\Exceptions\PageNotFoundException;
use InvalidArgumentException;

class FleetExtraService
{
    public function __construct(
        private readonly FleetExtraRepository $extras = new FleetExtraRepository(),
        private readonly AuditLogRepository $audit = new AuditLogRepository(),
        private readonly LookupRepository $lookups = new LookupRepository(),
    ) {
    }

    /** @return array<string,mixed> */
    public function workspace(int $companyId): array
    {
        return [
            'catalog' => $this->extras->catalog($companyId),
            'mappings' => $this->extras->mappings($companyId),
            'unmapped' => $this->extras->unmappedSources($companyId),
            'counts' => $this->extras->counts($companyId),
            'reservation_ids' => $this->extras->reservationIdsNeedingSnapshot($companyId),
        ];
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

        return $this->extras->transaction(function () use ($companyId, $sourceExtraId, $actorUserId, $observation, $existing, $data, $now): int {
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

    /** @return array{code:string,display_name:string,active:int,sort_order:int,notes:?string} */
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

        return [
            'code' => $code,
            'display_name' => $displayName,
            'active' => $this->activeValue($input['active'] ?? null),
            'sort_order' => $sortOrder,
            'notes' => $notes === '' ? null : $notes,
        ];
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
