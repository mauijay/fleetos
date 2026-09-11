<?php

namespace App\Repositories;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use RuntimeException;

class TripIncidentalReviewRepository
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    public function available(): bool
    {
        return $this->db->tableExists('trip_incidental_reviews')
            && $this->db->tableExists('incidental_review_policies')
            && $this->db->tableExists('incidental_earnings_plan_assignments');
    }

    /** @return array<string,mixed>|null */
    public function eligibleTrip(int $tripId): ?array
    {
        $row = $this->baseEligibleTrips()->where('trips.id', $tripId)->get()->getRowArray();
        return $row === null ? null : $row;
    }

    /** @return list<array<string,mixed>> */
    public function eligibleTrips(string $activationAtUtc, string $asOfUtc, int $limit): array
    {
        return $this->baseEligibleTrips()
            ->where('trips.ends_at >=', $activationAtUtc)
            ->where('trips.ends_at <=', $asOfUtc)
            ->where('reviews.id', null)
            ->orderBy('trips.ends_at', 'ASC')
            ->limit($limit)
            ->get()->getResultArray();
    }

    private function baseEligibleTrips(): \CodeIgniter\Database\BaseBuilder
    {
        return $this->db->table('turo_trips_normalized trips')
            ->select('trips.id, trips.fleet_vehicle_id, trips.booked_at, trips.ends_at, trips.canceled_at, vehicles.company_id')
            ->join('fleet_vehicles vehicles', 'vehicles.id = trips.fleet_vehicle_id')
            ->join('trip_incidental_reviews reviews', 'reviews.turo_trip_normalized_id = trips.id AND reviews.company_id = vehicles.company_id', 'left')
            ->where('trips.deleted_at', null)
            ->where('vehicles.deleted_at', null)
            ->where('trips.canceled_at', null)
            ->where('trips.ends_at IS NOT NULL', null, false);
    }

    public function create(array $data): ?int
    {
        $exists = $this->db->table('trip_incidental_reviews')->where([
            'company_id' => $data['company_id'], 'turo_trip_normalized_id' => $data['turo_trip_normalized_id'],
        ])->countAllResults() > 0;
        if ($exists) {
            return null;
        }
        if (! $this->db->table('trip_incidental_reviews')->insert($data)) {
            throw new RuntimeException('The trip incidental review could not be created.');
        }
        return (int) $this->db->insertID();
    }

    public function promoteDue(string $asOfUtc): int
    {
        $this->db->table('trip_incidental_reviews')
            ->where('status', 'waiting_for_review')
            ->where('review_after_at_utc <=', $asOfUtc)
            ->update(['status' => 'action_required', 'updated_at' => date('Y-m-d H:i:s')]);

        return $this->db->affectedRows();
    }

    /** @return array<string,mixed>|null */
    public function review(int $companyId, int $id): ?array
    {
        $row = $this->db->table('trip_incidental_reviews')->where(['company_id' => $companyId, 'id' => $id])->get()->getRowArray();
        return $row === null ? null : $row;
    }

    /** @return array<string,mixed>|null */
    public function reviewForTrip(int $companyId, int $tripId): ?array
    {
        $row = $this->db->table('trip_incidental_reviews reviews')
            ->select('reviews.*, trips.booked_at')
            ->join('turo_trips_normalized trips', 'trips.id = reviews.turo_trip_normalized_id')
            ->where(['reviews.company_id' => $companyId, 'reviews.turo_trip_normalized_id' => $tripId])
            ->get()->getRowArray();

        return $row === null ? null : $row;
    }

    /** @return list<array<string,mixed>> */
    public function queue(int $companyId): array
    {
        return $this->db->table('trip_incidental_reviews reviews')
            ->select('reviews.*, trips.turo_trip_id, trips.turo_reservation_id, trips.guest_name, vehicles.fleet_code, vehicles.display_name AS vehicle_name')
            ->join('turo_trips_normalized trips', 'trips.id = reviews.turo_trip_normalized_id')
            ->join('fleet_vehicles vehicles', 'vehicles.id = reviews.fleet_vehicle_id')
            ->where('reviews.company_id', $companyId)
            ->orderBy('reviews.review_after_at_utc', 'ASC')
            ->get()->getResultArray();
    }

    /** @return list<array<string,mixed>> */
    public function policies(int $companyId): array
    {
        return $this->db->table('incidental_review_policies')->where('company_id', $companyId)
            ->orderBy('filing_window_minutes', 'DESC')->get()->getResultArray();
    }

    /** @return list<array<string,mixed>> */
    public function earningsPlanAssignments(int $companyId): array
    {
        return $this->db->table('incidental_earnings_plan_assignments assignments')
            ->select('assignments.*, vehicles.fleet_code, vehicles.display_name AS vehicle_name')
            ->join('fleet_vehicles vehicles', 'vehicles.id = assignments.fleet_vehicle_id', 'left')
            ->where('assignments.company_id', $companyId)
            ->orderBy('assignments.effective_from_at_utc', 'DESC')
            ->orderBy('assignments.id', 'DESC')
            ->get()->getResultArray();
    }

    /** @return list<array{id:string,fleet_code:string,display_name:string}> */
    public function fleetVehicles(int $companyId): array
    {
        return $this->db->table('fleet_vehicles')
            ->select('id, fleet_code, display_name')
            ->where(['company_id' => $companyId, 'deleted_at' => null])
            ->orderBy('fleet_code', 'ASC')
            ->get()->getResultArray();
    }

    /** @return array<string,mixed>|null */
    public function fleetVehicle(int $companyId, int $vehicleId): ?array
    {
        $row = $this->db->table('fleet_vehicles')
            ->where(['company_id' => $companyId, 'id' => $vehicleId, 'deleted_at' => null])
            ->get()->getRowArray();

        return $row === null ? null : $row;
    }

    public function createEarningsPlanAssignment(array $data): int
    {
        $scope = ['company_id' => $data['company_id'], 'effective_from_at_utc' => $data['effective_from_at_utc']];
        $builder = $this->db->table('incidental_earnings_plan_assignments')->where($scope);
        $builder = $data['fleet_vehicle_id'] === null
            ? $builder->where('fleet_vehicle_id', null)
            : $builder->where('fleet_vehicle_id', $data['fleet_vehicle_id']);
        if ($builder->countAllResults() > 0) {
            throw new RuntimeException('An earnings plan assignment already exists for that scope and effective time.');
        }
        if (! $this->db->table('incidental_earnings_plan_assignments')->insert($data)) {
            throw new RuntimeException('The earnings plan assignment could not be created.');
        }

        return (int) $this->db->insertID();
    }

    /** @return array<string,mixed>|null */
    public function effectiveEarningsPlanAssignment(int $companyId, int $vehicleId, string $applicableAtUtc): ?array
    {
        $rows = $this->db->table('incidental_earnings_plan_assignments')
            ->where('company_id', $companyId)
            ->where('effective_from_at_utc <=', $applicableAtUtc)
            ->groupStart()
            ->where('fleet_vehicle_id', $vehicleId)
            ->orWhere('fleet_vehicle_id', null)
            ->groupEnd()
            ->orderBy('effective_from_at_utc', 'DESC')
            ->orderBy('id', 'DESC')
            ->get()->getResultArray();

        $fleetDefault = null;
        foreach ($rows as $row) {
            if ((int) ($row['fleet_vehicle_id'] ?? 0) === $vehicleId) {
                return $row;
            }
            $fleetDefault ??= $row;
        }

        return $fleetDefault;
    }

    /** @return list<array<string,mixed>> */
    public function unresolvedReviews(int $companyId, int $limit): array
    {
        return $this->db->table('trip_incidental_reviews reviews')
            ->select('reviews.*, trips.booked_at')
            ->join('turo_trips_normalized trips', 'trips.id = reviews.turo_trip_normalized_id')
            ->where('reviews.company_id', $companyId)
            ->where('reviews.earnings_plan_code_snapshot', null)
            ->orderBy('reviews.id', 'ASC')
            ->limit($limit)
            ->get()->getResultArray();
    }

    /** @return array<string,mixed>|null */
    public function policy(int $companyId, int $id): ?array
    {
        $row = $this->db->table('incidental_review_policies')->where(['company_id' => $companyId, 'id' => $id])->get()->getRowArray();
        return $row === null ? null : $row;
    }

    /** @return array<string,mixed>|null */
    public function activePolicy(int $companyId, string $planCode, string $tripEndedAtUtc): ?array
    {
        $row = $this->db->table('incidental_review_policies')
            ->where(['company_id' => $companyId, 'earnings_plan_code' => $planCode, 'status' => 'active'])
            ->where('effective_from_at_utc <=', $tripEndedAtUtc)
            ->groupStart()->where('effective_until_at_utc', null)->orWhere('effective_until_at_utc >', $tripEndedAtUtc)->groupEnd()
            ->orderBy('effective_from_at_utc', 'DESC')->get()->getRowArray();
        return $row === null ? null : $row;
    }

    public function updateReview(int $companyId, int $id, array $data): void
    {
        if (! $this->db->table('trip_incidental_reviews')->where(['company_id' => $companyId, 'id' => $id])->update($data)) {
            throw new RuntimeException('The trip incidental review could not be updated.');
        }
    }

    public function updatePolicy(int $companyId, int $id, array $data): void
    {
        if (! $this->db->table('incidental_review_policies')->where(['company_id' => $companyId, 'id' => $id])->update($data)) {
            throw new RuntimeException('The incidental review policy could not be updated.');
        }
    }

    /** @return array{ready:int,due_soon:int,overdue:int,total:int} */
    public function attentionSummary(int $companyId, string $asOfUtc): array
    {
        $soon = (new \DateTimeImmutable($asOfUtc, new \DateTimeZone('UTC')))->modify('+24 hours')->format('Y-m-d H:i:s');
        $row = $this->db->table('trip_incidental_reviews')
            ->select("SUM(CASE WHEN status NOT IN ('invoice_sent','no_invoice_needed') AND review_after_at_utc <= " . $this->db->escape($asOfUtc) . ' AND (filing_deadline_at_utc IS NULL OR filing_deadline_at_utc > ' . $this->db->escape($soon) . ') THEN 1 ELSE 0 END) AS ready', false)
            ->select("SUM(CASE WHEN status NOT IN ('invoice_sent','no_invoice_needed') AND review_after_at_utc <= " . $this->db->escape($asOfUtc) . ' AND filing_deadline_at_utc >= ' . $this->db->escape($asOfUtc) . ' AND filing_deadline_at_utc <= ' . $this->db->escape($soon) . ' THEN 1 ELSE 0 END) AS due_soon', false)
            ->select("SUM(CASE WHEN status NOT IN ('invoice_sent','no_invoice_needed') AND review_after_at_utc <= " . $this->db->escape($asOfUtc) . ' AND filing_deadline_at_utc < ' . $this->db->escape($asOfUtc) . ' THEN 1 ELSE 0 END) AS overdue', false)
            ->where('company_id', $companyId)->get()->getRowArray() ?? [];
        $summary = ['ready' => (int) ($row['ready'] ?? 0), 'due_soon' => (int) ($row['due_soon'] ?? 0), 'overdue' => (int) ($row['overdue'] ?? 0)];
        return $summary + ['total' => array_sum($summary)];
    }

    public function audit(int $companyId, string $table, int $recordId, string $action, ?array $old, ?array $new, int $actorUserId): void
    {
        $this->db->table('operational_fact_audits')->insert([
            'company_id' => $companyId, 'table_name' => $table, 'record_id' => $recordId, 'action' => $action,
            'old_values' => $old === null ? null : json_encode($old, JSON_THROW_ON_ERROR),
            'new_values' => $new === null ? null : json_encode($new, JSON_THROW_ON_ERROR),
            'actor_user_id' => $actorUserId, 'created_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
