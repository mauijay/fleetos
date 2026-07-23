<?php

namespace App\Repositories;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

class FleetVehicleRepository
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    public function findIdByTuroVehicleId(?string $turoVehicleId): ?int
    {
        if ($turoVehicleId === null || trim($turoVehicleId) === '') {
            return null;
        }

        $row = $this->db->table('vehicle_turo_listings')
            ->select('fleet_vehicle_id')
            ->where('turo_vehicle_id', trim($turoVehicleId))
            ->where('is_active', true)
            ->get()
            ->getRowArray();

        return $row === null ? null : (int) $row['fleet_vehicle_id'];
    }

    public function findIdByFleetCode(?string $fleetCode): ?int
    {
        if ($fleetCode === null || trim($fleetCode) === '') {
            return null;
        }

        $fleetCode = trim($fleetCode);
        $row = $this->db->table('fleet_vehicles')
            ->select('id')
            ->where('fleet_code', $fleetCode)
            ->get()
            ->getRowArray();

        if ($row !== null) {
            return (int) $row['id'];
        }

        $normalizedFleetCode = $this->normalizedFleetCode($fleetCode);
        if ($normalizedFleetCode === null) {
            return null;
        }

        $vehicles = $this->db->table('fleet_vehicles')
            ->select('id, fleet_code, display_name')
            ->get()
            ->getResultArray();

        foreach ($vehicles as $vehicle) {
            if (
                $this->normalizedFleetCode((string) ($vehicle['fleet_code'] ?? '')) === $normalizedFleetCode
                || $this->normalizedFleetCode((string) ($vehicle['display_name'] ?? '')) === $normalizedFleetCode
            ) {
                return (int) $vehicle['id'];
            }
        }

        return null;
    }

    private function normalizedFleetCode(?string $fleetCode): ?string
    {
        if ($fleetCode === null || trim($fleetCode) === '') {
            return null;
        }

        if (preg_match('/spaceship\D*0*(\d+)/i', $fleetCode, $matches) === 1) {
            return 'spaceship' . str_pad((string) ((int) $matches[1]), 2, '0', STR_PAD_LEFT);
        }

        $normalized = preg_replace('/[^a-z0-9]/i', '', strtolower($fleetCode));

        return $normalized === null || $normalized === '' ? null : $normalized;
    }
}
