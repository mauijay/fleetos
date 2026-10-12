<?php

namespace App\Services\Fleet;

use App\Repositories\VehicleDamageOccurrenceRepository;
use App\Repositories\VehicleDamageRepository;
use App\Repositories\VehicleWheelReadRepository;
use App\Services\Files\PrivateEvidenceStorageService;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use InvalidArgumentException;

final class VehicleWheelConditionReadService
{
    private readonly BaseConnection $db;
    private readonly VehicleWheelReadRepository $reads;
    private ?bool $available = null;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
        $this->reads = new VehicleWheelReadRepository($this->db);
    }

    /** Fixed schema checks are cached per service; data reads are exactly three at every batch size.
     * @param list<int> $vehicleIds @param array<int,int|array{trip_id:int}> $tripContexts
     */
    public function forVehicles(int $company, array $vehicleIds, array $tripContexts = []): array
    {
        VehicleDamageOccurrenceBasisSnapshot::positiveId($company);
        $ids = array_values(array_unique(array_map(VehicleDamageOccurrenceBasisSnapshot::positiveId(...), $vehicleIds)));
        if (count($ids) > 100) {
            throw new InvalidArgumentException('Select at most 100 vehicles per wheel projection.');
        }
        if ($ids === []) {
            return [];
        }
        $contexts = [];
        foreach ($tripContexts as $vehicle => $context) {
            $id = VehicleDamageOccurrenceBasisSnapshot::positiveId($vehicle);
            if (!in_array($id, $ids, true)) {
                throw new InvalidArgumentException('Trip context must match a requested vehicle.');
            }$contexts[$id] = VehicleDamageOccurrenceBasisSnapshot::positiveId(is_array($context) ? ($context['trip_id'] ?? null) : $context);
        }
        $this->available ??= (new VehicleDamageOccurrenceRepository($this->db))->available();
        $queries = $this->available ? $this->reads->projectionQueries($company, $ids, $contexts) : [];
        $conditions = $this->available ? $this->reads->rows($queries[0]) : $this->reads->ownedVehicles($company, $ids);
        $members = $this->available ? $this->reads->rows($queries[1]) : [];
        $repairs = $this->available ? $this->reads->rows($queries[2]) : [];
        $vehicles = [];
        $latest = [];
        $items = [];
        foreach ($conditions as $row) {
            $vehicle = (int)$row['owned_vehicle_id'];
            $vehicles[$vehicle] ??= ['vehicle_id' => $vehicle,'available' => $this->available,'wheels' => [],'unknown' => ['label' => 'Wheel position unknown','count' => 0,'invalid_records' => [],'invalid_count' => 0,'url' => $this->historyUrl($vehicle, 'UNKNOWN')],'conflict_count' => 0];
            if (isset($contexts[$vehicle]) && (!$this->available || (int)($row['context_trip_id'] ?? 0) !== $contexts[$vehicle])) {
                throw new InvalidArgumentException('Trip not found for this owned vehicle.');
            }
            if (($row['id'] ?? null) !== null) {
                $items[$vehicle][] = $row;
                $latest[(int)$row['id']] = $row['latest_event_id'] === null ? null : (int)$row['latest_event_id'];
            }
        }
        foreach ($contexts as $vehicle => $trip) {
            if (!isset($vehicles[$vehicle])) {
                throw new InvalidArgumentException('Trip context not found for this owned vehicle.');
            }
        }
        $occurrences = [];
        foreach ($members as $member) {
            $key = (int)$member['canonical_id'];
            $effect = $member['effect_code'];
            if ((int)$member['effect_rank'] === 1) {
                $occurrences[$key][$effect] = $member;
            }if ((int)$member['trip_rank'] === 1 && (int)$member['matches_trip'] === 1) {
                $occurrences[$key]['trip'][$effect] = $member;
            }
        }
        $work = array_column($repairs, null, 'condition_id');
        foreach ($vehicles as $vehicle => &$projection) {
            $rows = $items[$vehicle] ?? [];
            foreach ($rows as $row) {
                if ($row['zone_code'] === 'wheel_tire' && !VehicleWheelIdentity::isRecognizedWheelPanel($row['panel_code'])) {
                    $invalidAlias = $row['current_condition_item_id'] !== null && ($row['target_id'] === null || $row['target_alias'] !== null || (int)$row['target_id'] === (int)$row['id'] || $row['target_panel'] !== $row['panel_code'] || $row['target_type'] !== $row['damage_type_code'] || $row['target_zone'] !== $row['zone_code']);
                    if ($row['current_condition_item_id'] === null || $invalidAlias) {
                        ++$projection['unknown']['count'];
                    }
                    if ($invalidAlias) {
                        ++$projection['unknown']['invalid_count'];
                        if (count($projection['unknown']['invalid_records']) < 25) {
                            $projection['unknown']['invalid_records'][] = $row;
                        }
                    }
                }
            }
            foreach (VehicleWheelIdentity::choices() as $code => $identity) {
                $panel = $identity['panel_code'];
                $wheelRows = array_values(array_filter($rows, static fn (array $row): bool => ($row['panel_code'] === $panel && $row['damage_type_code'] === 'wheel_curb_rash') || ($row['target_panel'] === $panel && $row['target_type'] === 'wheel_curb_rash')));
                $state = VehicleDamageRepository::wheelStateFromRows($company, $vehicle, $panel, $wheelRows, $latest);
                $invalid = $state['alias_integrity'] !== 'valid' || array_any($wheelRows, static fn (array $row): bool => (int)$row['foreign_incoming'] === 1);
                $conflict = $invalid || $state['state'] === 'conflict';
                if ($conflict) {
                    ++$projection['conflict_count'];
                }
                $conditionId = !$conflict && $state['state'] === 'one' ? (int)$state['candidates'][0]['id'] : null;
                $item = $conditionId === null ? null : array_find($wheelRows, static fn (array $row): bool => (int)$row['id'] === $conditionId);
                $summary = $conditionId === null ? [] : ($occurrences[$conditionId] ?? []);
                $physical = array_values(array_filter([$summary['new_damage'] ?? null,$summary['worsened'] ?? null]));
                usort($physical, static fn (array $a, array $b): int => [$b['incident_occurred_at'] ?? $b['incident_discovered_at'],$b['created_at'],(int)$b['id']] <=> [$a['incident_occurred_at'] ?? $a['incident_discovered_at'],$a['created_at'],(int)$a['id']]);
                $historical = array_values(array_filter($wheelRows, static fn (array $row): bool => $row['current_condition_item_id'] === null));
                usort($historical, static fn (array $a, array $b): int => [$b['discovered_at'],(int)$b['id']] <=> [$a['discovered_at'],(int)$a['id']]);
                $contextId = $conditionId ?? (isset($historical[0]) ? (int)$historical[0]['id'] : null);
                $repair = $contextId === null ? null : ($work[$contextId] ?? null);
                $badge = isset($summary['trip']['worsened']) ? 'Worsened on this trip' : (isset($summary['trip']['observed_existing']) ? 'Observed on this trip' : null);
                $label = !$this->available ? 'Wheel history unavailable' : ($conflict ? 'Review conflicting records' : ($item === null ? 'No active rash recorded' : ($item['status_code'] === 'accepted_unrepaired' ? 'Accepted unrepaired' : 'Open rash')));
                // Current roots and aliases must remain visible ahead of older terminated episodes.
                $reviewRows = $wheelRows;
                $activeIds = array_column($state['candidates'], 'id');
                $priority = static fn (array $row): int => in_array((int)$row['id'], $activeIds, true) ? 0 : ($row['current_condition_item_id'] !== null || (int)$row['foreign_incoming'] === 1 ? 1 : 2);
                if ($conflict) {
                    usort($reviewRows, static fn (array $a, array $b): int => [$priority($a),(int)$a['id']] <=> [$priority($b),(int)$b['id']]);
                }
                $projection['wheels'][$code] = ['wheel_code' => $code,'label' => $identity['label'],'panel_code' => $panel,'state' => $conflict ? 'conflict' : $state['state'],'resolver_state' => $state['state'],'alias_integrity' => $invalid ? 'invalid' : 'valid','active_condition_id' => $conditionId,'status' => $label,'physical_status' => $item['status_code'] ?? null,'severity' => $item['severity_code'] ?? null,'description' => $item['description'] ?? null,'discovered_at' => $item['discovered_at'] ?? null,
                    'latest_condition_event' => $item === null ? null : ['id' => $item['latest_event_id'],'event_code' => $item['latest_event_code'],'occurred_at' => $item['latest_event_occurred_at'],'recorded_at' => $item['latest_event_recorded_at']],
                    'latest_physical_occurrence' => $physical[0] ?? null,'latest_observation' => $summary['observed_existing'] ?? null,'latest_worsening' => $summary['worsened'] ?? null,'repair_context' => $conflict ? null : $repair,'trip_badge' => $badge,'trip_badge_noncausal' => $badge === 'Observed on this trip','fingerprint' => $state['fingerprint'],'history_url' => $this->historyUrl($vehicle, $code),'read_actions' => ['history' => $this->historyUrl($vehicle, $code)],'conflict_records' => $conflict ? array_slice($reviewRows, 0, 25) : [],'conflict_record_count' => $conflict ? count($wheelRows) : 0];
            }
        }
        unset($projection);
        return $vehicles;
    }

    public function ledger(int $company, int $vehicle, array $input = [], int $page = 1): array
    {
        $projection = $this->ownedProjection($company, $vehicle);
        $filters = self::filters($input);
        $page = self::page($page);
        $rows = $this->reads->search($company, $vehicle, $filters, $page);
        return ['projection' => $projection,'vehicle' => ['id' => $vehicle],'filters' => $filters,'results' => array_slice($rows, 0, 25),'has_next' => count($rows) > 25,'page' => $page];
    }

    public function history(int $company, int $vehicle, string $wheel, int $episodePage = 1, ?int $episode = null, int $timelinePage = 1, int $repairPage = 1, int $after = 0): array
    {
        $projection = $this->ownedProjection($company, $vehicle);
        $wheel = $wheel === 'UNKNOWN' ? 'UNKNOWN' : VehicleWheelIdentity::normalize($wheel);
        $panel = $wheel === 'UNKNOWN' ? null : VehicleWheelIdentity::panel($wheel);
        $episodePage = self::page($episodePage);
        $timelinePage = self::page($timelinePage);
        $repairPage = self::page($repairPage);
        $episodes = $this->reads->episodes($company, $vehicle, $panel, $episodePage);
        $visible = array_slice($episodes, 0, 25);
        $maySelect = $wheel === 'UNKNOWN' ? $projection['unknown']['invalid_count'] === 0 : $projection['wheels'][$wheel]['state'] !== 'conflict';
        if ($maySelect) {
            $episode ??= $projection['wheels'][$wheel]['active_condition_id'] ?? (isset($visible[0]) ? (int)$visible[0]['id'] : null);
        }
        $selected = $episode === null ? null : $this->reads->condition($company, $vehicle, $episode);
        if ($episode !== null && ($selected === null || $selected['current_condition_item_id'] !== null || ($panel !== null && ($selected['panel_code'] !== $panel || $selected['damage_type_code'] !== 'wheel_curb_rash')) || ($panel === null && ($selected['zone_code'] !== 'wheel_tire' || VehicleWheelIdentity::isRecognizedWheelPanel($selected['panel_code']))))) {
            throw new InvalidArgumentException('Episode not found for this owned wheel.');
        }
        $timeline = $episode === null ? [] : $this->reads->timeline($company, $vehicle, $episode, $timelinePage);
        $repairs = $episode === null ? [] : $this->reads->repairHistory($company, $vehicle, $episode, $repairPage);
        $evidence = $episode === null ? ['rows' => [],'has_next' => false] : $this->gallery($company, $vehicle, $episode, null, $after);
        return ['projection' => $projection,'vehicle' => ['id' => $vehicle],'wheel' => $wheel,'wheel_label' => $wheel === 'UNKNOWN' ? 'Wheel position unknown' : VehicleWheelIdentity::label($wheel),'episodes' => $visible,'episode_has_next' => count($episodes) > 25,'episode_page' => $episodePage,'episode' => $selected,'timeline' => array_slice($timeline, 0, 25),'timeline_has_next' => count($timeline) > 25,'timeline_page' => $timelinePage,'repairs' => array_slice($repairs, 0, 25),'repair_has_next' => count($repairs) > 25,'repair_page' => $repairPage,'episode_evidence' => $evidence];
    }

    public function occurrence(int $company, int $vehicle, int $condition, int $membership, int $after = 0, int $baselineAfter = 0): array
    {
        $this->assertOriginal($company, $vehicle, $condition);
        $member = $this->reads->occurrence($company, $vehicle, $condition, $membership);
        if ($member === null) {
            throw new InvalidArgumentException('Occurrence not found for this owned condition.');
        }
        $comparison = VehicleWheelOccurrenceComparison::forOccurrence($member);
        $baseline = $comparison['baseline'];
        $baselinePhotos = ['rows' => [],'has_next' => false];
        if ($baseline !== null && $baseline['evidence_ids'] !== []) {
            $baselinePhotos = $this->gallery($company, $vehicle, $baseline['canonical_condition_item_id'], $baseline['incident_item_id'], $baselineAfter, $baseline['evidence_ids']);
        }
        return ['member' => $member,'comparison' => $comparison,'occurrence_photos' => $this->gallery($company, $vehicle, $condition, $membership, $after),'baseline_photos' => $baselinePhotos];
    }

    public function gallery(int $company, int $vehicle, int $condition, ?int $membership, int $after = 0, ?array $selected = null): array
    {
        $this->assertOriginal($company, $vehicle, $condition);
        if ($membership !== null && $this->reads->occurrence($company, $vehicle, $condition, $membership) === null) {
            throw new InvalidArgumentException('Occurrence not found for this evidence parent.');
        }
        $rows = $this->reads->gallery($company, $vehicle, $condition, $membership, $after, $selected);
        $hasNext = count($rows) > 12;
        $rows = array_slice($rows, 0, 12);
        $storage = new PrivateEvidenceStorageService();
        foreach ($rows as &$row) {
            $available = $storage->resolve($row, 'damage-evidence/company-'.$company.'/vehicle-'.$vehicle, VehicleDamageOccurrenceEvidenceService::MIME_TYPES) !== null;
            $row['available'] = $available;
            $row['original_filename'] = $row['file_original_filename'];
            $row['url'] = $available ? ($row['vehicle_damage_incident_item_id'] === null ? '/fleet/vehicles/'.$vehicle.'/wheel-history/evidence/'.(int)$row['vehicle_damage_item_id'].'/'.(int)$row['id'] : '/fleet/vehicles/'.$vehicle.'/wheel-damage/'.(int)$row['vehicle_damage_item_id'].'/occurrences/'.(int)$row['vehicle_damage_incident_item_id'].'/photos/'.(int)$row['id']) : null;
            $row['role'] = in_array($row['label'], VehicleDamageOccurrenceEvidenceService::ROLES, true) ? $row['label'] : null;
            unset($row['file_path'],$row['file_checksum'],$row['file_storage_disk']);
        }
        unset($row);
        return ['rows' => $rows,'has_next' => $hasNext,'after' => $after,'next_after' => $hasNext ? (int)$rows[11]['id'] : null];
    }

    private function assertOriginal(int $company, int $vehicle, int $condition): array
    {
        $original = $this->reads->condition($company, $vehicle, $condition);
        if ($original === null || ($original['current_condition_item_id'] !== null && ($original['target_id'] === null || $original['target_alias'] !== null || (int)$original['target_id'] === $condition || $original['target_panel'] !== $original['panel_code'] || $original['target_type'] !== $original['damage_type_code'] || $original['target_zone'] !== $original['zone_code']))) {
            throw new InvalidArgumentException('Evidence condition has an invalid owned relationship.');
        }
        return $original;
    }

    private function ownedProjection(int $company, int $vehicle): array
    {
        $projection = $this->forVehicles($company, [$vehicle])[$vehicle] ?? null;
        if ($projection === null || !$projection['available']) {
            throw new InvalidArgumentException('Wheel history not available for this owned vehicle.');
        }
        return $projection;
    }

    public static function filters(array $input): array
    {
        $filters = [];
        foreach (['wheel' => '','damage_type' => '','physical_status' => '','identity' => 'current','q' => ''] as $field => $default) {
            if (isset($input[$field]) && !is_string($input[$field])) {
                throw new InvalidArgumentException('Invalid Damage filter.');
            }$filters[$field] = trim($input[$field] ?? $default);
        }
        if (!in_array($filters['wheel'], ['','LF','RF','RR','LR','UNKNOWN'], true) || ($filters['damage_type'] !== '' && !isset(VehicleDamageService::DAMAGE_TYPES[$filters['damage_type']])) || !in_array($filters['physical_status'], ['','current','repaired','resolved'], true) || !in_array($filters['identity'], ['current','recorded'], true) || mb_strlen($filters['q']) > 190) {
            throw new InvalidArgumentException('Choose a supported Damage filter.');
        }
        return $filters;
    }

    public static function page(int $page): int
    {
        if ($page < 1 || $page > 100000) {
            throw new InvalidArgumentException('Choose a valid history page.');
        }return $page;
    }

    private function historyUrl(int $vehicle, string $wheel): string
    {
        return '/fleet/vehicles/'.$vehicle.'/wheel-history/'.$wheel;
    }
}
