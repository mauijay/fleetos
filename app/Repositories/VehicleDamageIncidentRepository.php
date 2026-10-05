<?php

namespace App\Repositories;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

class VehicleDamageIncidentRepository
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    public function migrated(): bool
    {
        return $this->db->tableExists('vehicle_damage_incidents');
    }

    public function insert(array $values): int
    {
        $this->db->table('vehicle_damage_incidents')->insert($values);

        return (int) $this->db->insertID();
    }

    public function insertMembership(array $values): int
    {
        $this->db->table('vehicle_damage_incident_items')->insert($values);

        return (int) $this->db->insertID();
    }

    /** @return array<string,mixed>|null */
    public function incident(int $companyId, int $vehicleId, int $id): ?array
    {
        return $this->builder($companyId, $vehicleId)->where('incidents.id', $id)->get()->getRowArray();
    }

    /** @return list<array<string,mixed>> */
    public function forVehicle(int $companyId, int $vehicleId): array
    {
        return $this->migrated() ? $this->builder($companyId, $vehicleId)->orderBy('incidents.discovered_at', 'DESC')->orderBy('incidents.id', 'DESC')->get()->getResultArray() : [];
    }

    /** @return list<array<string,mixed>> */
    public function memberships(int $companyId, int $vehicleId, int $incidentId): array
    {
        return $this->db->table('vehicle_damage_incident_items links')->select('links.*, damage.description, damage.current_condition_item_id')
            ->join('vehicle_damage_incidents incidents', 'incidents.id = links.vehicle_damage_incident_id AND incidents.company_id = links.company_id')
            ->join('vehicle_damage_items damage', 'damage.id = links.vehicle_damage_item_id AND damage.company_id = links.company_id AND damage.fleet_vehicle_id = incidents.fleet_vehicle_id')
            ->where('links.company_id', $companyId)->where('incidents.fleet_vehicle_id', $vehicleId)->where('incidents.id', $incidentId)->orderBy('links.id')->get()->getResultArray();
    }

    public function update(int $companyId, int $vehicleId, int $id, array $values): void
    {
        $this->db->table('vehicle_damage_incidents')->where('company_id', $companyId)->where('fleet_vehicle_id', $vehicleId)->where('id', $id)->update($values);
    }

    /** Parent ownership authorizes incident audit history. @return list<array<string,mixed>> */
    public function history(int $companyId, int $vehicleId, int $incidentId): array
    {
        return $this->db->table('audit_logs audit')->select('audit.*')
            ->join('vehicle_damage_incidents incidents', "incidents.id = audit.record_id AND audit.table_name = 'vehicle_damage_incidents'")
            ->where('incidents.company_id', $companyId)->where('incidents.fleet_vehicle_id', $vehicleId)->where('incidents.id', $incidentId)
            ->orderBy('audit.id')->get()->getResultArray();
    }

    /** @return list<array<string,mixed>> */
    public function trips(int $companyId, int $vehicleId): array
    {
        return $this->db->table('turo_trips_normalized')->select('id, turo_reservation_id')
            ->where('company_id', $companyId)->where('fleet_vehicle_id', $vehicleId)->where('deleted_at', null)->orderBy('id', 'DESC')->get()->getResultArray();
    }

    private function builder(int $companyId, int $vehicleId): \CodeIgniter\Database\BaseBuilder
    {
        return $this->db->table('vehicle_damage_incidents incidents')->select('incidents.*, trips.turo_reservation_id')
            ->join('turo_trips_normalized trips', 'trips.id = incidents.turo_trip_normalized_id AND trips.company_id = incidents.company_id AND trips.fleet_vehicle_id = incidents.fleet_vehicle_id AND trips.deleted_at IS NULL', 'left')
            ->where('incidents.company_id', $companyId)->where('incidents.fleet_vehicle_id', $vehicleId);
    }
}
