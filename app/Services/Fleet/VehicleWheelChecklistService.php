<?php

namespace App\Services\Fleet;

use App\Repositories\VehicleWheelReadRepository;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/** Read-only staging context. Wheel commands and readiness keep their existing authorities. */
class VehicleWheelChecklistService
{
    private readonly BaseConnection $db;
    private readonly VehicleWheelConditionReadService $reads;
    private ?bool $available = null;

    public function __construct(?BaseConnection $db = null, ?VehicleWheelConditionReadService $reads = null)
    {
        $this->db = $db ?? Database::connect();
        $this->reads = $reads ?? new VehicleWheelConditionReadService($this->db);
    }

    public function context(int $company, int $checklist, ?DateTimeImmutable $asOf = null): ?array
    {
        if ($company < 1 || $checklist < 1 || ! $this->db->tableExists('trip_movement_checklists')) {
            return null;
        }
        $t = fn (string $name): string => $this->db->escapeIdentifiers($this->db->prefixTable($name));
        $events = $t('trip_movement_events');
        $trips = $t('turo_trips_normalized');
        $scope = 'e.company_id=v.company_id AND e.fleet_vehicle_id=v.id AND e.voided_at IS NULL';
        $sql = "SELECT c.*,v.company_id,v.display_name,t.starts_at,t.ends_at,t.turo_reservation_id,s.code AS trip_status_code,
            EXISTS(SELECT 1 FROM {$events} e WHERE {$scope} AND e.turo_trip_normalized_id=t.id AND e.event_code='actual_handoff') AS has_handoff,
            EXISTS(SELECT 1 FROM {$events} e WHERE {$scope} AND e.turo_trip_normalized_id=t.id AND e.event_code IN ('actual_return','vehicle_recovered')) AS has_return,
            EXISTS(SELECT 1 FROM {$events} e JOIN {$trips} later ON later.id=e.turo_trip_normalized_id AND later.fleet_vehicle_id=v.id AND later.deleted_at IS NULL WHERE {$scope} AND later.starts_at>t.starts_at AND e.event_code='actual_handoff') AS has_later_handoff
            FROM {$t('trip_movement_checklists')} c JOIN {$t('fleet_vehicles')} v ON v.id=c.fleet_vehicle_id
            JOIN {$trips} t ON t.id=c.turo_trip_normalized_id AND t.fleet_vehicle_id=v.id
            LEFT JOIN {$t('lookup_values')} s ON s.id=t.trip_status_lookup_value_id
            WHERE c.id={$checklist} AND v.company_id={$company} AND v.deleted_at IS NULL AND t.deleted_at IS NULL";
        $row = $this->db->query($sql)->getRowArray();
        if ($row === null) {
            return null;
        }
        $now = ($asOf ?? new DateTimeImmutable('now', new DateTimeZone('Pacific/Honolulu')))->setTimezone(new DateTimeZone('Pacific/Honolulu'))->format('Y-m-d H:i:s');
        $reason = match (true) {
            $row['movement_type'] !== 'pickup' => 'Return context: review wheel history here. Record current damage through the vehicle Damage workflow.',
            $row['completed_at'] !== null => 'This checklist is completed. Wheel history remains available.',
            ! in_array($row['trip_status_code'], ['booked', 'in_progress'], true) => 'This trip is historical or inactive. Wheel history remains available.',
            $row['scheduled_at'] !== $row['starts_at'] => 'This checklist has an older schedule snapshot. Open the current pickup checklist for wheel actions.',
            (bool) $row['has_handoff'] => 'Guest handoff has been recorded. Review history here; record later damage through the vehicle Damage workflow.',
            (bool) $row['has_return'] || (bool) $row['has_later_handoff'] || $row['ends_at'] === null || $row['ends_at'] <= $now => 'This pickup preparation context has ended. Wheel history remains available.',
            default => null,
        };
        return $row + ['can_record' => $reason === null, 'read_only_reason' => $reason];
    }

    public function forChecklist(int $company, int $checklist, ?DateTimeImmutable $asOf = null): ?array
    {
        $context = $this->context($company, $checklist, $asOf);
        if ($context === null) {
            return null;
        }
        $vehicle = (int) $context['fleet_vehicle_id'];
        $this->available ??= (new \App\Repositories\VehicleDamageOccurrenceRepository($this->db))->available();
        $projection = $this->available ? ($this->reads->forVehicles($company, [$vehicle], [$vehicle => (int) $context['turo_trip_normalized_id']])[$vehicle] ?? null) : null;
        return ['context' => $context, 'projection' => $projection];
    }

    public function priorComparison(int $company, array $context, array $wheel): ?array
    {
        $members = array_filter([$wheel['latest_physical_occurrence'], $wheel['latest_observation']]);
        usort($members, static fn (array $a, array $b): int => (int) $b['id'] <=> (int) $a['id']);
        $latest = $members[0] ?? null;
        if ($latest === null) {
            return null;
        }
        $vehicle = (int) $context['fleet_vehicle_id'];
        $member = (new VehicleWheelReadRepository($this->db))->occurrence($company, $vehicle, (int) $latest['vehicle_damage_item_id'], (int) $latest['id']);
        if ($member === null) {
            throw new InvalidArgumentException('Prior occurrence is not available for this owned wheel.');
        }
        return ['member' => $member, 'comparison' => VehicleWheelOccurrenceComparison::forOccurrence($member),
            'url' => '/fleet/vehicles/'.$vehicle.'/wheel-history/'.(int) $member['vehicle_damage_item_id'].'/occurrences/'.(int) $member['id']];
    }

    public static function actionUrl(int $checklist, string $wheel, string $action): string
    {
        return '/operations/checklists/'.$checklist.'/wheel-curb-rash/'.VehicleWheelIdentity::normalize($wheel).'/'.$action;
    }
}
