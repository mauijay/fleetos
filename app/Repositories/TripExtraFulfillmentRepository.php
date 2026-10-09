<?php

namespace App\Repositories;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use RuntimeException;

class TripExtraFulfillmentRepository
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    public function storageExists(): bool
    {
        return $this->db->tableExists('trip_extra_fulfillments');
    }

    /** Writer-only lock, shared with import/configuration and movement commands. */
    public function lockOwnedTrip(int $companyId, int $tripId): void
    {
        if ($this->db->DBDriver === 'MySQLi') {
            (new FleetExtraRepository($this->db))->lockCompanyObservations($companyId);
        }
        $suffix = $this->db->DBDriver === 'MySQLi' ? ' FOR UPDATE' : '';
        $trip = $this->db->query('SELECT trips.id FROM ' . $this->db->prefixTable('turo_trips_normalized') . ' trips JOIN '
            . $this->db->prefixTable('fleet_vehicles') . ' vehicles ON vehicles.id = trips.fleet_vehicle_id'
            . ' WHERE trips.id = ? AND vehicles.company_id = ? AND trips.deleted_at IS NULL' . $suffix, [$tripId, $companyId])->getRowArray();
        if ($trip === null) {
            throw new RuntimeException('Extra fulfillment trip not found for this company.');
        }
    }

    public function attachUnmatchedForTrip(int $companyId, int $tripId): void
    {
        if (! $this->db->tableExists('turo_extra_reservation_snapshots')) {
            return;
        }
        $trip = $this->db->table('turo_trips_normalized trips')->select('trips.turo_trip_id, trips.turo_reservation_id')
            ->join('fleet_vehicles vehicles', 'vehicles.id = trips.fleet_vehicle_id')
            ->where('vehicles.company_id', $companyId)->where('trips.id', $tripId)->where('trips.deleted_at', null)
            ->get()->getRowArray();
        if ($trip === null) {
            return;
        }
        $ids = array_values(array_unique(array_filter([(string) $trip['turo_trip_id'], (string) $trip['turo_reservation_id']])));
        $matches = (new FleetExtraRepository($this->db))->tripsByReservationIds($companyId, $ids);
        $ids = array_values(array_filter($ids, static fn (string $id): bool => isset($matches[$id]) && (int) $matches[$id]['id'] === $tripId));
        if ($ids === []) {
            return;
        }
        $this->transaction(function () use ($companyId, $tripId, $ids): void {
            foreach (['turo_extra_selections', 'turo_extra_reservation_snapshots'] as $table) {
                $this->db->table($table)->where('company_id', $companyId)->whereIn('turo_reservation_id', $ids)
                    ->where('turo_trip_normalized_id', null)->update(['turo_trip_normalized_id' => $tripId]);
            }
        });
    }

    /** @param list<int> $selectionIds @return list<array<string, mixed>> */
    public function operationalRows(int $companyId, array $selectionIds = [], ?int $fleetExtraId = null, ?string $sourceExtraId = null): array
    {
        if (! $this->storageExists()) {
            return [];
        }
        $builder = $this->baseSelectionQuery($companyId);
        if ($selectionIds !== []) {
            $builder->whereIn('selections.id', array_values(array_unique(array_map('intval', $selectionIds))));
        }
        if ($fleetExtraId !== null) {
            $builder->where('extras.id', $fleetExtraId);
        }
        if ($sourceExtraId !== null) {
            $builder->where('selections.source_extra_id', $sourceExtraId);
        }

        return $builder->orderBy('selections.id', 'ASC')->get()->getResultArray();
    }

    /** @param list<int> $tripIds @return list<array<string, mixed>> */
    public function currentForTrips(int $companyId, array $tripIds): array
    {
        if (! $this->storageExists() || $tripIds === []) {
            return [];
        }

        return $this->baseSelectionQuery($companyId)
            ->whereIn('selections.turo_trip_normalized_id', array_values(array_unique(array_map('intval', $tripIds))))
            ->where('selections.removed_at', null)
            ->orderBy('extras.sort_order', 'ASC')
            ->orderBy('extras.display_name', 'ASC')
            ->orderBy('selections.id', 'ASC')
            ->get()->getResultArray();
    }

    /**
     * Current and removed selections, including those never reconciled.
     *
     * @param  list<int>                  $tripIds
     * @return list<array<string, mixed>>
     */
    public function forTripsWithHistory(int $companyId, array $tripIds, ?int $rowLimit = null): array
    {
        if (! $this->storageExists() || $tripIds === []) {
            return [];
        }

        $query = $this->baseSelectionQuery($companyId)
            ->whereIn('selections.turo_trip_normalized_id', array_values(array_unique(array_map('intval', $tripIds))))
            ->orderBy('extras.sort_order', 'ASC')
            ->orderBy('extras.display_name', 'ASC')
            ->orderBy('selections.id', 'ASC');
        if ($rowLimit !== null) {
            $query->limit($rowLimit);
        }
        return $query->get()->getResultArray();
    }

    /** @return array<string, mixed>|null */
    public function rowForCompany(int $companyId, int $fulfillmentId): ?array
    {
        if (! $this->storageExists()) {
            return null;
        }
        $row = $this->baseSelectionQuery($companyId)
            ->where('fulfillments.id', $fulfillmentId)
            ->get(1)->getRowArray();

        return $row === null ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function fulfillmentForSelection(int $companyId, int $selectionId): ?array
    {
        if (! $this->storageExists()) {
            return null;
        }
        $row = $this->db->table('trip_extra_fulfillments')
            ->where(['company_id' => $companyId, 'turo_extra_selection_id' => $selectionId])
            ->get()->getRowArray();

        return $row === null ? null : $row;
    }

    /** Writer-only source history, read after owned parent validation/locking. @return list<array<string, mixed>> */
    public function observationsForRow(array $row): array
    {
        if (! $this->db->tableExists('turo_extra_reservation_snapshots')) {
            return [];
        }
        return $this->db->table('turo_extra_reservation_snapshots')
            ->where('company_id', (int) $row['company_id'])->where('turo_reservation_id', (string) $row['turo_reservation_id'])
            ->get()->getResultArray();
    }

    /** @param list<string> $reservationIds @return list<int> */
    public function selectionIdsForReservations(int $companyId, array $reservationIds): array
    {
        if ($reservationIds === []) {
            return [];
        }
        return array_map('intval', array_column($this->db->table('turo_extra_selections')
            ->select('id')
            ->where('company_id', $companyId)
            ->whereIn('turo_reservation_id', $reservationIds)
            ->get()->getResultArray(), 'id'));
    }

    public function create(array $data): int
    {
        $this->db->table('trip_extra_fulfillments')->insert($data);

        return (int) $this->db->insertID();
    }

    public function update(int $companyId, int $fulfillmentId, array $data): void
    {
        $this->db->table('trip_extra_fulfillments')
            ->where(['company_id' => $companyId, 'id' => $fulfillmentId])
            ->update($data);
    }

    public function audit(int $fulfillmentId, int $companyId, string $action, ?int $actorUserId, ?array $before, array $after): void
    {
        $this->db->table('trip_extra_fulfillment_audits')->insert([
            'trip_extra_fulfillment_id' => $fulfillmentId,
            'company_id' => $companyId,
            'action' => $action,
            'actor_user_id' => $actorUserId,
            'before_values' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
            'after_values' => json_encode($after, JSON_THROW_ON_ERROR),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** @return list<array<string, mixed>> */
    public function audits(int $companyId, int $fulfillmentId): array
    {
        if (! $this->db->tableExists('trip_extra_fulfillment_audits')) {
            return [];
        }
        return $this->db->table('trip_extra_fulfillment_audits')
            ->where(['company_id' => $companyId, 'trip_extra_fulfillment_id' => $fulfillmentId])
            ->orderBy('id', 'ASC')->get()->getResultArray();
    }

    /** @param list<int> $tripIds @return array<int, list<array<string, mixed>>> */
    public function linkedCommitments(int $companyId, array $tripIds): array
    {
        if ($tripIds === [] || ! $this->db->tableExists('fleet_trip_commitments')
            || ! in_array('fleet_extra_id', $this->db->getFieldNames('fleet_trip_commitments'), true)) {
            return [];
        }
        $rows = $this->db->table('fleet_trip_commitments commitments')
            ->select('commitments.*')
            ->where('commitments.company_id', $companyId)
            ->whereIn('commitments.turo_trip_normalized_id', array_values(array_unique(array_map('intval', $tripIds))))
            ->where('commitments.state', 'active')
            ->where('commitments.fleet_extra_id IS NOT NULL', null, false)
            ->orderBy('commitments.id', 'ASC')->get()->getResultArray();
        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['turo_trip_normalized_id']][] = $row;
        }

        return $result;
    }

    public function hasActiveEvent(int $companyId, int $tripId, string $eventCode): bool
    {
        return $this->db->table('trip_movement_events events')
            ->join('turo_trips_normalized trips', 'trips.id = events.turo_trip_normalized_id AND trips.fleet_vehicle_id = events.fleet_vehicle_id AND trips.deleted_at IS NULL')
            ->join('fleet_vehicles vehicles', 'vehicles.id = trips.fleet_vehicle_id AND vehicles.company_id = events.company_id')
            ->where('vehicles.company_id', $companyId)
            ->where('events.turo_trip_normalized_id', $tripId)
            ->where('events.event_code', $eventCode)
            ->where('events.voided_at', null)
            ->countAllResults() > 0;
    }

    /** @param list<int> $tripIds @return array<int, true> */
    public function activeHandoffTripIds(int $companyId, array $tripIds): array
    {
        if ($tripIds === []) {
            return [];
        }
        $rows = $this->db->table('trip_movement_events events')
            ->select('events.turo_trip_normalized_id')
            ->join('turo_trips_normalized trips', 'trips.id = events.turo_trip_normalized_id AND trips.fleet_vehicle_id = events.fleet_vehicle_id AND trips.deleted_at IS NULL')
            ->join('fleet_vehicles vehicles', 'vehicles.id = trips.fleet_vehicle_id AND vehicles.company_id = events.company_id')
            ->where('vehicles.company_id', $companyId)
            ->whereIn('events.turo_trip_normalized_id', array_values(array_unique(array_map('intval', $tripIds))))
            ->where('events.event_code', 'actual_handoff')
            ->where('events.voided_at', null)
            ->groupBy('events.turo_trip_normalized_id')
            ->get()->getResultArray();

        return array_fill_keys(array_map('intval', array_column($rows, 'turo_trip_normalized_id')), true);
    }

    public function transaction(callable $callback): mixed
    {
        $this->db->transBegin();
        try {
            $result = $callback();
            if ($this->db->transStatus() === false) {
                throw new RuntimeException('Extra fulfillment transaction failed.');
            }
            $this->db->transCommit();

            return $result;
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }

    private function baseSelectionQuery(int $companyId): \CodeIgniter\Database\BaseBuilder
    {
        return $this->db->table('turo_extra_selections selections')
            ->select('selections.*')
            ->select('selections.id AS selection_id, selections.company_id, selections.turo_trip_normalized_id, selections.turo_reservation_id')
            ->select('selections.source_extra_id, selections.reservation_state_extra_id, selections.quantity, selections.removed_at')
            ->select('mappings.fleet_extra_id, extras.code AS fleet_extra_code, extras.display_name AS fleet_extra_name, extras.active AS fleet_extra_active')
            ->select('extras.fulfillment_type, extras.requires_operator_confirmation, extras.readiness_blocking, extras.default_action_label, extras.fulfillment_phase')
            ->select('trips.fleet_vehicle_id, trips.canceled_at AS trip_canceled_at, statuses.code AS trip_status_code')
            ->select('fulfillments.id AS fulfillment_id, fulfillments.state AS fulfillment_state, fulfillments.current_basis_hash, fulfillments.completed_basis_hash')
            ->select('fulfillments.completed_at, fulfillments.completed_by_user_id, fulfillments.completion_note, fulfillments.created_at AS fulfillment_created_at, fulfillments.updated_at AS fulfillment_updated_at')
            ->join('fleet_extra_source_mappings mappings', "mappings.company_id = selections.company_id AND mappings.source_system = 'turo' AND mappings.source_extra_id = selections.source_extra_id", 'left')
            ->join('fleet_extras extras', 'extras.id = mappings.fleet_extra_id AND extras.company_id = mappings.company_id', 'left')
            ->join('turo_trips_normalized trips', 'trips.id = selections.turo_trip_normalized_id AND trips.deleted_at IS NULL')
            ->join('fleet_vehicles vehicles', 'vehicles.id = trips.fleet_vehicle_id AND vehicles.company_id = selections.company_id')
            ->join('lookup_values statuses', 'statuses.id = trips.trip_status_lookup_value_id', 'left')
            ->join('trip_extra_fulfillments fulfillments', 'fulfillments.company_id = selections.company_id AND fulfillments.turo_extra_selection_id = selections.id', 'left')
            ->where('selections.company_id', $companyId);
    }
}
