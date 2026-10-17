<?php

namespace App\Repositories;

use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;

/** Bulk evidence only. Child reads always follow owned, active vehicle selection. */
final class FleetOperationalReconciliationRepository
{
    public const MAX_ROWS = 10000;

    public function __construct(private readonly BaseConnection $db)
    {
    }

    /** @param list<int>|null $vehicleIds @return array<string, mixed> */
    public function load(int $companyId, ?array $vehicleIds, DateTimeImmutable $asOf): array
    {
        $vehicles = (new OperationalFactsRepository($this->db))->activeFleetVehiclesForCompany($companyId, $asOf->format('Y-m-d'));
        if ($vehicleIds !== null) {
            $vehicles = array_values(array_filter($vehicles, static fn (array $v): bool => in_array((int) $v['id'], $vehicleIds, true)));
        }
        $this->bounded($vehicles);
        $ids = array_map('intval', array_column($vehicles, 'id'));
        if ($ids === []) {
            return ['vehicles' => [], 'trips' => [], 'events' => [], 'checklists' => [], 'policies' => [], 'pressure' => []];
        }
        $vehicles = $this->db->table('fleet_vehicles vehicles')->select('vehicles.id, vehicles.company_id, vehicles.fleet_number, vehicles.fleet_code, vehicles.display_name, vehicles.out_of_service_date, statuses.code AS status_code, statuses.is_available_for_booking')
            ->join('vehicle_statuses statuses', 'statuses.id = vehicles.vehicle_status_id', 'left')
            ->where('vehicles.company_id', $companyId)->whereIn('vehicles.id', $ids)->orderBy('vehicles.id', 'ASC')->get()->getResultArray();
        $trips = $this->db->table('turo_trips_normalized trips')->select('trips.id, trips.fleet_vehicle_id, trips.starts_at, trips.ends_at, trips.canceled_at, statuses.code AS trip_status_code')
            ->join('fleet_vehicles vehicles', 'vehicles.id = trips.fleet_vehicle_id')
            ->join('lookup_values statuses', 'statuses.id = trips.trip_status_lookup_value_id', 'left')
            ->where('vehicles.company_id', $companyId)->whereIn('vehicles.id', $ids)->where('trips.deleted_at', null)
            ->orderBy('trips.starts_at', 'ASC')->orderBy('trips.id', 'ASC')->limit(self::MAX_ROWS + 1)->get()->getResultArray();
        $checklists = $this->db->table('trip_movement_checklists checklists')->select('checklists.*')
            ->join('turo_trips_normalized trips', 'trips.id = checklists.turo_trip_normalized_id AND trips.fleet_vehicle_id = checklists.fleet_vehicle_id')
            ->join('fleet_vehicles vehicles', 'vehicles.id = trips.fleet_vehicle_id')
            ->where('vehicles.company_id', $companyId)->whereIn('vehicles.id', $ids)->where('trips.deleted_at', null)
            ->orderBy('checklists.id', 'ASC')->limit(self::MAX_ROWS + 1)->get()->getResultArray();
        $events = $this->db->table('trip_movement_events events')->select('events.*')
            ->join('fleet_vehicles vehicles', 'vehicles.id = events.fleet_vehicle_id AND vehicles.company_id = events.company_id')
            ->join('turo_trips_normalized trips', 'trips.id = events.turo_trip_normalized_id AND trips.fleet_vehicle_id = events.fleet_vehicle_id AND trips.deleted_at IS NULL', 'left')
            ->where('events.company_id', $companyId)->whereIn('vehicles.id', $ids)
            ->groupStart()->where('events.turo_trip_normalized_id', null)->orWhere('trips.id IS NOT NULL', null, false)->groupEnd()
            ->where('events.voided_at', null)->where('events.occurred_at <=', $asOf->format('Y-m-d H:i:s'))
            ->orderBy('events.occurred_at', 'ASC')->orderBy('events.id', 'ASC')->limit(self::MAX_ROWS + 1)->get()->getResultArray();
        $policies = $this->db->table('vehicle_health_policies')->where('company_id', $companyId)->whereIn('fleet_vehicle_id', $ids)
            ->where('reminder_code', 'tire_pressure_check')->where('is_enabled', true)->limit(self::MAX_ROWS + 1)->get()->getResultArray();
        $pressure = $this->db->table('vehicle_health_observations observations')->select('observations.*, pressure.lf_psi, pressure.rf_psi, pressure.lr_psi, pressure.rr_psi, pressure.recommended_psi')
            ->join('vehicle_tire_pressure_observations pressure', 'pressure.vehicle_health_observation_id = observations.id')
            ->where('observations.company_id', $companyId)->whereIn('observations.fleet_vehicle_id', $ids)
            ->where('observations.observation_code', 'tire_pressure')->where('observations.voided_at', null)
            ->where('observations.observed_at <=', $asOf->format('Y-m-d H:i:s'))
            ->where('NOT EXISTS (SELECT 1 FROM ' . $this->db->protectIdentifiers($this->db->prefixTable('vehicle_health_observations')) . ' replacements WHERE replacements.supersedes_observation_id = observations.id)', null, false)
            ->orderBy('observations.observed_at', 'DESC')->orderBy('observations.id', 'DESC')->limit(self::MAX_ROWS + 1)->get()->getResultArray();
        foreach ([$trips, $checklists, $events, $policies, $pressure] as $rows) {
            $this->bounded($rows);
        }
        return compact('vehicles', 'trips', 'checklists', 'events', 'policies', 'pressure');
    }

    private function bounded(array $rows): void
    {
        if (count($rows) > self::MAX_ROWS) {
            throw new \RuntimeException('Reconciliation exceeds the 10000-row authority limit. Request fewer vehicles; no partial projection was returned.');
        }
    }
}
