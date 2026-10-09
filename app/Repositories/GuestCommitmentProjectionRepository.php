<?php

namespace App\Repositories;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;

/** Read-only bulk loader. Parent ownership is established before any child data is read. */
class GuestCommitmentProjectionRepository
{
    public const MAX_AUTHORITY_ROWS = 10000;

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null, private readonly int $rowLimit = self::MAX_AUTHORITY_ROWS)
    {
        if ($rowLimit < 1 || $rowLimit > self::MAX_AUTHORITY_ROWS) {
            throw new \InvalidArgumentException('Guest commitment row limit must be between 1 and ' . self::MAX_AUTHORITY_ROWS . '.');
        }
        $this->db = $db ?? Database::connect();
    }

    /** @param list<int> $tripIds @return array<string, mixed> */
    public function load(int $companyId, array $tripIds, DateTimeImmutable $asOf): array
    {
        if (count($tripIds) > $this->rowLimit) {
            $this->limitExceeded('requested trips');
        }
        if ($this->db->transDepth > 0) {
            return $this->loadSnapshot($companyId, $tripIds, $asOf);
        }
        // A page and board must see one database snapshot across all authorities.
        if ($this->db->DBDriver === 'MySQLi') {
            $this->db->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $this->db->query('SET TRANSACTION READ ONLY');
        }
        $this->db->transBegin();
        try {
            $result = $this->loadSnapshot($companyId, $tripIds, $asOf);
            $this->db->transCommit();
            return $result;
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }

    /** @param list<int> $tripIds @return array<string, mixed> */
    private function loadSnapshot(int $companyId, array $tripIds, DateTimeImmutable $asOf): array
    {
        $trips = $this->db->table('turo_trips_normalized trips')
            ->select('trips.*, vehicles.company_id, statuses.code AS trip_status_code')
            ->select('returns.location_class AS return_location_class, returns.source_text AS return_location_source_text')
            ->select('(SELECT return_checklists.id FROM ' . $this->db->prefixTable('trip_movement_checklists') . " return_checklists WHERE return_checklists.turo_trip_normalized_id = trips.id AND return_checklists.fleet_vehicle_id = trips.fleet_vehicle_id AND return_checklists.movement_type = 'return' ORDER BY return_checklists.scheduled_at DESC, return_checklists.id DESC LIMIT 1) AS return_checklist_id", false)
            ->join('fleet_vehicles vehicles', 'vehicles.id = trips.fleet_vehicle_id')
            ->join('lookup_values statuses', 'statuses.id = trips.trip_status_lookup_value_id', 'left')
            ->join('scheduled_movement_locations returns', "returns.turo_trip_normalized_id = trips.id AND returns.movement_type = 'return'", 'left')
            ->where('vehicles.company_id', $companyId)->where('trips.deleted_at', null)
            ->whereIn('trips.id', $tripIds)->limit($this->rowLimit + 1)->get()->getResultArray();
        $this->assertWithinLimit('trips', $trips);
        $owned = array_map('intval', array_column($trips, 'id'));
        if ($owned === []) {
            return ['trips' => [], 'selections' => [], 'snapshots' => [], 'failures' => [], 'manuals' => [], 'handoffs' => [], 'audits' => [], 'mappings' => []];
        }
        $reservationIds = [];
        foreach ($trips as $trip) {
            foreach (['turo_reservation_id', 'turo_trip_id'] as $key) {
                if (trim((string) ($trip[$key] ?? '')) !== '') {
                    $reservationIds[] = (string) $trip[$key];
                }
            }
        }
        $reservationIds = array_values(array_unique($reservationIds));
        $fulfillments = new TripExtraFulfillmentRepository($this->db);
        $selections = $fulfillments->forTripsWithHistory($companyId, $owned, $this->rowLimit + 1);
        $this->assertWithinLimit('selections', $selections);
        $snapshots = $this->db->table('turo_extra_reservation_snapshots')
            ->where('company_id', $companyId)->whereIn('turo_reservation_id', $reservationIds)
            ->orderBy('observed_at', 'DESC')->orderBy('id', 'DESC')->limit($this->rowLimit + 1)->get()->getResultArray();
        $this->assertWithinLimit('snapshots', $snapshots);
        $failures = (new FleetExtraRepository($this->db))->verificationFailures($companyId, $reservationIds, $this->rowLimit + 1);
        $this->assertWithinLimit('verification failures', $failures);
        $manuals = $this->db->table('fleet_trip_commitments commitments')->select('commitments.*, extras.display_name AS fleet_extra_name')
            ->join('fleet_extras extras', 'extras.id = commitments.fleet_extra_id AND extras.company_id = commitments.company_id', 'left')
            ->where('commitments.company_id', $companyId)->whereIn('commitments.turo_trip_normalized_id', $owned)
            ->orderBy('commitments.id', 'ASC')->limit($this->rowLimit + 1)->get()->getResultArray();
        $this->assertWithinLimit('manual commitments', $manuals);
        $handoffs = (new FleetExtraRepository($this->db))->verificationHandoffTripIds($companyId, $owned, $asOf);
        $audits = $this->db->table('trip_extra_fulfillment_audits audits')->select('audits.*')
            ->join('trip_extra_fulfillments fulfillments', 'fulfillments.id = audits.trip_extra_fulfillment_id AND fulfillments.company_id = audits.company_id')
            ->join('turo_extra_selections selections', 'selections.id = fulfillments.turo_extra_selection_id AND selections.company_id = fulfillments.company_id')
            ->where('audits.company_id', $companyId)->whereIn('selections.turo_trip_normalized_id', $owned)
            ->orderBy('audits.id', 'ASC')->limit($this->rowLimit + 1)->get()->getResultArray();
        $this->assertWithinLimit('fulfillment audits', $audits);
        $sourceIds = array_column($selections, 'source_extra_id');
        $sourceItemCount = 0;
        foreach ($snapshots as $snapshot) {
            $payload = json_decode((string) $snapshot['source_payload'], true);
            $sourceItemCount += count($payload['extras'] ?? []);
            if ($sourceItemCount > $this->rowLimit) {
                $this->limitExceeded('snapshot items');
            }
            foreach ($payload['extras'] ?? [] as $extra) {
                $sourceIds[] = (string) $extra['extra_id'];
            }
        }
        $mappings = $sourceIds === [] ? [] : $this->db->table('fleet_extra_source_mappings mappings')
            ->select('mappings.source_extra_id, mappings.fleet_extra_id, extras.display_name AS fleet_extra_name, extras.active AS fleet_extra_active')
            ->select('extras.fulfillment_type, extras.requires_operator_confirmation, extras.readiness_blocking, extras.default_action_label, extras.fulfillment_phase')
            ->join('fleet_extras extras', 'extras.id = mappings.fleet_extra_id AND extras.company_id = mappings.company_id', 'left')
            ->where('mappings.company_id', $companyId)->where('mappings.source_system', 'turo')
            ->whereIn('mappings.source_extra_id', array_values(array_unique($sourceIds)))->limit($this->rowLimit + 1)->get()->getResultArray();
        $this->assertWithinLimit('source mappings', $mappings);

        return compact('trips', 'selections', 'snapshots', 'failures', 'manuals', 'handoffs', 'audits', 'mappings');
    }

    private function assertWithinLimit(string $authority, array $rows): void
    {
        if (count($rows) > $this->rowLimit) {
            $this->limitExceeded($authority);
        }
    }

    private function limitExceeded(string $authority): never
    {
        throw new \RuntimeException('Guest commitment projection exceeds the ' . $this->rowLimit . '-row safety limit for ' . $authority . '. No partial guest commitments were returned. Request fewer trips or review retained history before retrying.');
    }
}
