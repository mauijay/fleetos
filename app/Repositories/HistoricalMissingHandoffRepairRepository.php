<?php

namespace App\Repositories;

use RuntimeException;

/** Existing audit receipts and vehicle ranges; no new persistence authority. */
class HistoricalMissingHandoffRepairRepository extends HistoricalMovementRepairRepository
{
    public const RECEIPT_ACTION = 'historical_missing_handoff_applied';

    /** Serialize the existing audit receipt range, including its empty gap. */
    public function lockReceipts(): void
    {
        $this->rows('operational_fact_audits', ['action' => self::RECEIPT_ACTION], true, 1000);
    }

    public function commandReceipt(string $uuid): ?array
    {
        $rows = $this->db->table('operational_fact_audits')->where('action', self::RECEIPT_ACTION)
            ->like('new_values', '"operation_uuid":"' . $uuid . '"')->orderBy('id')->limit(2)->get()->getResultArray();
        if (count($rows) > 1) {
            throw new RuntimeException('Ambiguous historical handoff receipt.');
        }
        if ($rows === []) {
            return null;
        }
        $receipt = json_decode($rows[0]['new_values'], true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($receipt) || ($receipt['operation_uuid'] ?? null) !== $uuid) {
            throw new RuntimeException('Malformed historical handoff receipt.');
        }
        return ['audit' => $rows[0], 'receipt' => $receipt];
    }

    /** Extra projection inputs follow the same locked vehicle/trip scope. */
    public function lockInputs(int $company, int $vehicle): void
    {
        $this->lockVehicle($company, $vehicle);
        // Commercial projectors include reservation snapshots and mappings that
        // may not yet be linked to a trip. Lock bounded company ranges as well
        // as their child receipts; do not guess a trip FK on fulfillment tables.
        $this->commercialState($company, true);
        $this->rows('airport_deliveries', ['fleet_vehicle_id' => $vehicle], true, 1000);
        // Bind corrections/receipts without permitting an audit phantom during apply.
        $this->scopedAudits($vehicle, true);
    }

    public function state(int $vehicle): array
    {
        $state = $this->vehicleState($vehicle);
        $state['airport_deliveries'] = $this->rows('airport_deliveries', ['fleet_vehicle_id' => $vehicle], false, 1000);
        $state['operational_fact_audits'] = $this->scopedAudits($vehicle, false);
        $company = (int) ($state['fleet_vehicles'][0]['company_id'] ?? 0);
        $state += $this->commercialState($company, false);
        $state['vehicle_statuses'] = $this->rows('vehicle_statuses', [], false, 1000);
        $state['lookup_values'] = $this->rows('lookup_values', [], false, 5000);
        return $state;
    }

    public function requireTransactionalTables(): void
    {
        parent::requireTransactionalTables();
        if ($this->db->DBDriver === 'MySQLi') {
            $tables = array_map($this->db->prefixTable(...), [...self::COMMERCIAL_TABLES, 'airport_deliveries', 'vehicle_statuses', 'lookup_values', 'turo_import_errors']);
            $rows = $this->db->query('SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (' . implode(',', array_fill(0, count($tables), '?')) . ')', $tables)->getResultArray();
            if (count($rows) !== count($tables) || array_filter($rows, static fn (array $row): bool => $row['ENGINE'] !== 'InnoDB')) {
                throw new RuntimeException('Historical handoff projection inputs require InnoDB.');
            }
        }
    }

    private const COMMERCIAL_TABLES = ['trip_extra_fulfillments', 'trip_extra_fulfillment_audits', 'turo_extra_selections', 'turo_extra_reservation_snapshots', 'fleet_trip_commitment_audits', 'fleet_extras', 'fleet_extra_source_mappings'];

    private function commercialState(int $company, bool $lock): array
    {
        $state = [];
        foreach (self::COMMERCIAL_TABLES as $table) {
            $state[$table] = $this->rows($table, ['company_id' => $company], $lock, 5000);
        }
        $sql = $this->db->table('turo_import_errors')->where('raw_table', 'turo_extra_reservation_snapshots')
            ->where("JSON_EXTRACT(raw_payload, '$.company_id')", $company, false)->orderBy('id')->limit(5001)->getCompiledSelect();
        if ($lock && $this->db->DBDriver === 'MySQLi') {
            $sql .= ' FOR UPDATE';
        }
        $state['turo_import_errors'] = $this->db->query($sql)->getResultArray();
        if (count($state['turo_import_errors']) > 5000) {
            throw new RuntimeException('Extras failure inspection limit exceeded.');
        }
        if ($lock) {
            $this->rows('vehicle_statuses', [], true, 1000);
            $this->rows('lookup_values', [], true, 5000);
        }
        return $state;
    }

    private function scopedAudits(int $vehicle, bool $lock): array
    {
        $tables = ['fleet_vehicles' => [$vehicle]];
        foreach (['turo_trips_normalized', 'trip_movement_events', 'movement_assessments', 'trip_movement_checklists', 'vehicle_positioning_plans', 'vehicle_operational_profiles', 'vehicle_operational_capabilities'] as $table) {
            $tables[$table] = array_column($this->rows($table, ['fleet_vehicle_id' => $vehicle], $lock, 5000), 'id');
        }
        $builder = $this->db->table('operational_fact_audits')->groupStart();
        foreach ($tables as $table => $ids) {
            if ($ids !== []) {
                $builder->orGroupStart()->where('table_name', $table)->whereIn('record_id', $ids)->groupEnd();
            }
        }
        $sql = $builder->groupEnd()->orderBy('id')->limit(5001)->getCompiledSelect();
        if ($lock && $this->db->DBDriver === 'MySQLi') {
            $sql .= ' FOR UPDATE';
        }
        $rows = $this->db->query($sql)->getResultArray();
        if (count($rows) > 5000) {
            throw new RuntimeException('Historical handoff audit inspection limit exceeded.');
        }
        return $rows;
    }
}
