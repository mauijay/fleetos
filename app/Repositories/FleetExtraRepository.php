<?php

namespace App\Repositories;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use RuntimeException;

class FleetExtraRepository
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    public function transaction(callable $callback): mixed
    {
        $this->db->transBegin();
        try {
            $result = $callback();
            if ($this->db->transStatus() === false) {
                throw new RuntimeException('Extras database transaction failed.');
            }
            $this->db->transCommit();

            return $result;
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }

    public function supportsFulfillmentConfiguration(): bool
    {
        if ($this->db->DBDriver === 'SQLite3') {
            $rows = $this->db->query('PRAGMA table_info(' . $this->db->prefixTable('fleet_extras') . ')')->getResultArray();

            return in_array('fulfillment_type', array_column($rows, 'name'), true);
        }

        return $this->db->fieldExists('fulfillment_type', 'fleet_extras');
    }

    /** @return list<array<string,mixed>> */
    public function catalog(int $companyId): array
    {
        return $this->db->table('fleet_extras extras')
            ->select('extras.*')
            ->select('COUNT(DISTINCT mappings.id) AS mapping_count', false)
            ->select('COUNT(DISTINCT selections.id) AS selection_count', false)
            ->join('fleet_extra_source_mappings mappings', 'mappings.fleet_extra_id = extras.id AND mappings.company_id = extras.company_id', 'left')
            ->join('turo_extra_selections selections', "mappings.source_system = 'turo' AND selections.company_id = extras.company_id AND selections.source_extra_id = mappings.source_extra_id AND selections.removed_at IS NULL", 'left')
            ->where('extras.company_id', $companyId)
            ->groupBy('extras.id')
            ->orderBy('extras.active', 'DESC')
            ->orderBy('extras.sort_order', 'ASC')
            ->orderBy('extras.display_name', 'ASC')
            ->get()->getResultArray();
    }

    /** @return list<array<string, mixed>> */
    public function options(int $companyId): array
    {
        if (! $this->db->tableExists('fleet_extras')) {
            return [];
        }

        return $this->db->table('fleet_extras')
            ->where('company_id', $companyId)
            ->orderBy('active', 'DESC')
            ->orderBy('sort_order', 'ASC')
            ->orderBy('display_name', 'ASC')
            ->get()->getResultArray();
    }

    /** @return array<string,mixed>|null */
    public function extra(int $companyId, int $extraId): ?array
    {
        $row = $this->db->table('fleet_extras')->where(['company_id' => $companyId, 'id' => $extraId])->get()->getRowArray();

        return $row === null ? null : $row;
    }

    /** @return array<string,mixed>|null */
    public function extraByCode(int $companyId, string $code): ?array
    {
        $row = $this->db->table('fleet_extras')->where(['company_id' => $companyId, 'code' => $code])->get()->getRowArray();

        return $row === null ? null : $row;
    }

    public function createExtra(array $data): int
    {
        $this->db->table('fleet_extras')->insert($data);

        return (int) $this->db->insertID();
    }

    public function updateExtra(int $companyId, int $extraId, array $data): void
    {
        $this->db->table('fleet_extras')->where(['company_id' => $companyId, 'id' => $extraId])->update($data);
    }

    public function extraHasActivity(int $companyId, int $extraId): bool
    {
        return $this->db->table('fleet_extra_source_mappings mappings')
            ->join('turo_extra_selections selections', "mappings.source_system = 'turo' AND selections.company_id = mappings.company_id AND selections.source_extra_id = mappings.source_extra_id")
            ->where(['mappings.company_id' => $companyId, 'mappings.fleet_extra_id' => $extraId])
            ->countAllResults() > 0;
    }

    /** @return list<array<string,mixed>> */
    public function mappings(int $companyId): array
    {
        return $this->db->table('fleet_extra_source_mappings mappings')
            ->select('mappings.*, extras.code AS fleet_extra_code, extras.display_name AS fleet_extra_name, extras.active AS fleet_extra_active')
            ->join('fleet_extras extras', 'extras.id = mappings.fleet_extra_id AND extras.company_id = mappings.company_id')
            ->where('mappings.company_id', $companyId)
            ->orderBy('extras.sort_order', 'ASC')
            ->orderBy('extras.display_name', 'ASC')
            ->orderBy('mappings.source_extra_id', 'ASC')
            ->get()->getResultArray();
    }

    /** @return array<string,mixed>|null */
    public function mapping(int $companyId, string $sourceSystem, string $sourceExtraId): ?array
    {
        $row = $this->db->table('fleet_extra_source_mappings')->where([
            'company_id' => $companyId,
            'source_system' => $sourceSystem,
            'source_extra_id' => $sourceExtraId,
        ])->get()->getRowArray();

        return $row === null ? null : $row;
    }

    public function createMapping(array $data): int
    {
        $this->db->table('fleet_extra_source_mappings')->insert($data);

        return (int) $this->db->insertID();
    }

    public function updateMapping(int $companyId, int $mappingId, array $data): void
    {
        $this->db->table('fleet_extra_source_mappings')->where(['company_id' => $companyId, 'id' => $mappingId])->update($data);
    }

    /** @return array<string,mixed>|null */
    public function latestSourceObservation(int $companyId, string $sourceExtraId): ?array
    {
        $row = $this->db->table('turo_extra_selections')
            ->where(['company_id' => $companyId, 'source_extra_id' => $sourceExtraId])
            ->orderBy('last_observed_at', 'DESC')
            ->orderBy('id', 'DESC')
            ->get()->getRowArray();

        return $row === null ? null : $row;
    }

    /** @param list<string> $sourceExtraIds @return array<string,array<string,mixed>> */
    public function mappingsBySourceIds(int $companyId, array $sourceExtraIds): array
    {
        if ($sourceExtraIds === []) {
            return [];
        }
        $rows = $this->db->table('fleet_extra_source_mappings')
            ->where(['company_id' => $companyId, 'source_system' => 'turo'])
            ->whereIn('source_extra_id', $sourceExtraIds)
            ->get()->getResultArray();

        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row['source_extra_id']] = $row;
        }

        return $result;
    }

    /** @param list<string> $reservationIds @return array<string,array<string,mixed>> */
    public function tripsByReservationIds(int $companyId, array $reservationIds): array
    {
        if ($reservationIds === []) {
            return [];
        }
        $rows = $this->db->table('turo_trips_normalized trips')
            ->select('trips.id, trips.turo_trip_id, trips.turo_reservation_id, trips.fleet_vehicle_id, vehicles.company_id')
            ->join('fleet_vehicles vehicles', 'vehicles.id = trips.fleet_vehicle_id')
            ->where('vehicles.company_id', $companyId)
            ->where('trips.deleted_at', null)
            ->groupStart()
                ->whereIn('trips.turo_trip_id', $reservationIds)
                ->orWhereIn('trips.turo_reservation_id', $reservationIds)
            ->groupEnd()
            ->get()->getResultArray();

        $result = [];
        foreach ($rows as $row) {
            foreach (['turo_trip_id', 'turo_reservation_id'] as $field) {
                $key = trim((string) ($row[$field] ?? ''));
                if ($key !== '') {
                    $result[$key] = $row;
                }
            }
        }

        return $result;
    }

    /** @param list<string> $reservationIds @return array<string,array<string,mixed>> */
    public function selectionsByIdentity(int $companyId, array $reservationIds): array
    {
        if ($reservationIds === []) {
            return [];
        }
        $rows = $this->db->table('turo_extra_selections')
            ->where('company_id', $companyId)
            ->whereIn('turo_reservation_id', $reservationIds)
            ->get()->getResultArray();
        $result = [];
        foreach ($rows as $row) {
            $result[$this->selectionKey((string) $row['turo_reservation_id'], (string) $row['reservation_state_extra_id'])] = $row;
        }

        return $result;
    }

    /** @param list<string> $reservationIds @return array<string,string> */
    public function latestSnapshotObservations(int $companyId, array $reservationIds): array
    {
        if ($reservationIds === []) {
            return [];
        }
        $rows = $this->db->table('turo_extra_reservation_snapshots')
            ->select('turo_reservation_id, MAX(observed_at) AS observed_at', false)
            ->where('company_id', $companyId)
            ->whereIn('turo_reservation_id', $reservationIds)
            ->groupBy('turo_reservation_id')
            ->get()->getResultArray();

        return array_column($rows, 'observed_at', 'turo_reservation_id');
    }

    public function createSnapshot(array $data): int
    {
        $data['source_payload'] = json_encode($data['source_payload'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $this->db->table('turo_extra_reservation_snapshots')->insert($data);

        return (int) $this->db->insertID();
    }

    public function createSelection(array $data): int
    {
        $data['source_payload'] = json_encode($data['source_payload'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $this->db->table('turo_extra_selections')->insert($data);

        return (int) $this->db->insertID();
    }

    public function updateSelection(int $companyId, int $selectionId, array $data): void
    {
        if (isset($data['source_payload']) && is_array($data['source_payload'])) {
            $data['source_payload'] = json_encode($data['source_payload'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        }
        $this->db->table('turo_extra_selections')->where(['company_id' => $companyId, 'id' => $selectionId])->update($data);
    }

    /** @param list<string> $presentStateExtraIds */
    public function markMissingSelectionsRemoved(int $companyId, string $reservationId, array $presentStateExtraIds, string $observedAt, int $snapshotId): int
    {
        $builder = $this->db->table('turo_extra_selections')
            ->select('id')
            ->where(['company_id' => $companyId, 'turo_reservation_id' => $reservationId, 'removed_at' => null])
            ->where('last_observed_at <=', $observedAt);
        if ($presentStateExtraIds !== []) {
            $builder->whereNotIn('reservation_state_extra_id', $presentStateExtraIds);
        }
        $ids = array_map('intval', array_column($builder->get()->getResultArray(), 'id'));
        if ($ids === []) {
            return 0;
        }
        $this->db->table('turo_extra_selections')->whereIn('id', $ids)->update([
            'last_snapshot_id' => $snapshotId,
            'last_observed_at' => $observedAt,
            'removed_at' => $observedAt,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return count($ids);
    }

    public function touchMappingFromObservation(int $companyId, string $sourceExtraId, array $extra, string $observedAt): void
    {
        $this->db->table('fleet_extra_source_mappings')
            ->where(['company_id' => $companyId, 'source_system' => 'turo', 'source_extra_id' => $sourceExtraId])
            ->where('last_seen_at <=', $observedAt)
            ->update([
                'source_type' => $extra['type'],
                'latest_source_label' => $extra['label'],
                'latest_source_description' => $extra['description'],
                'last_seen_at' => $observedAt,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
    }

    /** @return list<array<string,mixed>> */
    public function unmappedSources(int $companyId): array
    {
        return $this->db->table('turo_extra_selections selections')
            ->select('selections.source_extra_id, MAX(selections.source_type) AS source_type', false)
            ->select('MAX(selections.source_label) AS source_label, MAX(selections.source_description) AS source_description', false)
            ->select('MIN(selections.unit_price) AS minimum_price, MAX(selections.unit_price) AS maximum_price', false)
            ->select('MAX(selections.currency_code) AS currency_code, COUNT(*) AS selection_count, MAX(selections.last_observed_at) AS last_observed_at', false)
            ->join('fleet_extra_source_mappings mappings', "mappings.company_id = selections.company_id AND mappings.source_system = 'turo' AND mappings.source_extra_id = selections.source_extra_id", 'left')
            ->where('selections.company_id', $companyId)
            ->where('mappings.id', null)
            ->groupBy('selections.source_extra_id')
            ->orderBy('last_observed_at', 'DESC')
            ->get()->getResultArray();
    }

    /** @return list<string> */
    public function reservationIdsNeedingSnapshot(int $companyId, int $limit = 500): array
    {
        $rows = $this->db->table('turo_trips_normalized trips')
            ->select("COALESCE(NULLIF(trips.turo_reservation_id, ''), trips.turo_trip_id) AS reservation_id", false)
            ->join('fleet_vehicles vehicles', 'vehicles.id = trips.fleet_vehicle_id')
            ->join('turo_extra_reservation_snapshots snapshots', 'snapshots.company_id = vehicles.company_id AND snapshots.turo_reservation_id = COALESCE(NULLIF(trips.turo_reservation_id, \'\'), trips.turo_trip_id) AND snapshots.snapshot_complete = 1', 'left', false)
            ->where('vehicles.company_id', $companyId)
            ->where('trips.deleted_at', null)
            ->where('snapshots.id', null)
            ->groupBy('reservation_id')
            ->orderBy('trips.starts_at', 'DESC')
            ->limit($limit)
            ->get()->getResultArray();

        return array_values(array_filter(array_map(static fn (array $row): string => trim((string) $row['reservation_id']), $rows)));
    }

    /** @return array{selection_count:int,unmapped_count:int,snapshot_count:int} */
    public function counts(int $companyId): array
    {
        $selectionCount = $this->db->table('turo_extra_selections')->where(['company_id' => $companyId, 'removed_at' => null])->countAllResults();
        $snapshotCount = $this->db->table('turo_extra_reservation_snapshots')->where('company_id', $companyId)->countAllResults();

        return ['selection_count' => $selectionCount, 'unmapped_count' => count($this->unmappedSources($companyId)), 'snapshot_count' => $snapshotCount];
    }

    public function selectionKey(string $reservationId, string $reservationStateExtraId): string
    {
        return $reservationId . "\x1F" . $reservationStateExtraId;
    }
}
