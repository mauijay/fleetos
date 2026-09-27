<?php

namespace App\Repositories;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

class SuperchargerReconciliationRepository
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /** @return array<string, mixed>|null */
    public function batchByHash(int $companyId, string $hash): ?array
    {
        $row = $this->db->table('tesla_charging_import_batches')
            ->where(['company_id' => $companyId, 'source_hash' => $hash])
            ->get()->getRowArray();

        return $row ?: null;
    }

    public function createBatch(int $companyId, string $filename, string $hash, int $actorUserId): int
    {
        $this->db->table('tesla_charging_import_batches')->insert([
            'company_id' => $companyId,
            'source_filename' => $filename,
            'source_hash' => $hash,
            'status_code' => 'processing',
            'imported_by' => $actorUserId,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return (int) $this->db->insertID();
    }

    /** @param array<string, int> $counts */
    public function completeBatch(int $batchId, array $counts, string $status = 'completed', ?string $error = null): void
    {
        $this->db->table('tesla_charging_import_batches')->where('id', $batchId)->update([
            'status_code' => $status,
            'row_count' => $counts['rows'] ?? 0,
            'imported_count' => $counts['imported'] ?? 0,
            'duplicate_count' => $counts['duplicate'] ?? 0,
            'review_count' => $counts['review'] ?? 0,
            'rejected_count' => $counts['rejected'] ?? 0,
            'error_message' => $error,
            'completed_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** @param array<string, mixed> $payload */
    public function createImportRow(int $companyId, int $batchId, int $rowNumber, string $rowHash, array $payload, string $status, ?string $issueCode = null, ?string $issueDetail = null): int
    {
        $this->db->table('tesla_charging_import_rows')->insert([
            'company_id' => $companyId,
            'tesla_charging_import_batch_id' => $batchId,
            'row_number' => $rowNumber,
            'row_hash' => $rowHash,
            'raw_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'status_code' => $status,
            'issue_code' => $issueCode,
            'issue_detail' => $issueDetail,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return (int) $this->db->insertID();
    }

    public function updateImportRow(int $rowId, string $status, ?string $issueCode = null, ?string $issueDetail = null): void
    {
        $this->db->table('tesla_charging_import_rows')->where('id', $rowId)->update([
            'status_code' => $status,
            'issue_code' => $issueCode,
            'issue_detail' => $issueDetail,
        ]);
    }

    /** @return list<array<string, mixed>> */
    public function vehiclesByVin(int $companyId, string $vin): array
    {
        return $this->db->table('fleet_vehicles')
            ->where('company_id', $companyId)
            ->where('UPPER(vin) = ' . $this->db->escape(strtoupper($vin)), null, false)
            ->where('deleted_at', null)
            ->get()->getResultArray();
    }

    public function vinExistsOutsideCompany(int $companyId, string $vin): bool
    {
        return $this->db->table('fleet_vehicles')
            ->where('company_id !=', $companyId)
            ->where('UPPER(vin) = ' . $this->db->escape(strtoupper($vin)), null, false)
            ->where('deleted_at', null)
            ->countAllResults() > 0;
    }

    /** @return list<array<string, mixed>> */
    public function eligibleTripsContaining(int $companyId, int $vehicleId, string $at): array
    {
        return $this->eligibleTripsBase($companyId, $vehicleId)
            ->where('trips.starts_at <=', $at)
            ->where('trips.ends_at >=', $at)
            ->orderBy('trips.starts_at', 'ASC')
            ->get()->getResultArray();
    }

    /** @return array<string, mixed>|null */
    public function eligibleTripById(int $companyId, int $vehicleId, int $tripId): ?array
    {
        $row = $this->eligibleTripsBase($companyId, $vehicleId)
            ->where('trips.id', $tripId)
            ->get()->getRowArray();

        return $row ?: null;
    }

    /** @return array{previous:?array<string,mixed>,next:?array<string,mixed>} */
    public function surroundingEligibleTrips(int $companyId, int $vehicleId, string $at): array
    {
        $previous = $this->eligibleTripsBase($companyId, $vehicleId)
            ->where('trips.ends_at <', $at)->orderBy('trips.ends_at', 'DESC')->limit(1)->get()->getRowArray();
        $next = $this->eligibleTripsBase($companyId, $vehicleId)
            ->where('trips.starts_at >', $at)->orderBy('trips.starts_at', 'ASC')->limit(1)->get()->getRowArray();

        return ['previous' => $previous ?: null, 'next' => $next ?: null];
    }

    /** @return array<string, mixed>|null */
    public function sessionByFingerprint(string $fingerprint): ?array
    {
        $row = $this->db->table('charging_sessions')->where('source_session_fingerprint', $fingerprint)->get()->getRowArray();

        return $row ?: null;
    }

    public function createSession(array $data): int
    {
        $this->db->table('charging_sessions')->insert(array_merge($data, [
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]));

        return (int) $this->db->insertID();
    }

    /** @param array<string, mixed> $classification */
    public function updateSessionClassification(int $sessionId, array $classification): void
    {
        $this->db->table('charging_sessions')->where('id', $sessionId)->update([
            'turo_trip_normalized_id' => $classification['authoritative_trip_id'],
            'custody_classification' => $classification['custody_classification'],
            'custody_basis_code' => $classification['custody_basis_code'],
            'custody_basis_event_id' => $classification['custody_basis_event_id'],
            'candidate_turo_trip_normalized_id' => $classification['candidate_trip_id'],
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function lineExists(int $companyId, string $fingerprint): bool
    {
        return $this->db->table('tesla_charging_line_items')
            ->where(['company_id' => $companyId, 'source_line_fingerprint' => $fingerprint])
            ->countAllResults() > 0;
    }

    public function createLineItem(array $data): int
    {
        $this->db->table('tesla_charging_line_items')->insert(array_merge($data, ['created_at' => date('Y-m-d H:i:s')]));

        return (int) $this->db->insertID();
    }

    public function refreshSessionCost(int $sessionId): void
    {
        $row = $this->db->table('tesla_charging_line_items')
            ->selectSum('total_inc_vat_amount', 'total')
            ->where('charging_session_id', $sessionId)->get()->getRowArray();
        $this->db->table('charging_sessions')->where('id', $sessionId)->update([
            'cost_amount' => (string) ($row['total'] ?? '0.00'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function ensureCase(int $companyId, int $vehicleId, int $tripId): int
    {
        $existing = $this->db->table('supercharger_reimbursement_cases')
            ->where(['company_id' => $companyId, 'turo_trip_normalized_id' => $tripId])
            ->get()->getRowArray();
        if ($existing) {
            return (int) $existing['id'];
        }
        $now = date('Y-m-d H:i:s');
        $this->db->table('supercharger_reimbursement_cases')->insert([
            'company_id' => $companyId,
            'fleet_vehicle_id' => $vehicleId,
            'turo_trip_normalized_id' => $tripId,
            'workflow_state_code' => 'not_submitted',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $this->db->insertID();
    }

    /** @return list<array<string, mixed>> */
    public function casesForCompany(int $companyId): array
    {
        return $this->db->table('supercharger_reimbursement_cases cases')
            ->select('cases.*, trips.turo_trip_id, trips.turo_reservation_id, trips.guest_name, trips.starts_at, trips.ends_at, trips.on_trip_ev_charging_amount, trips.post_trip_ev_charging_amount, vehicles.fleet_code, vehicles.display_name AS vehicle_name')
            ->join('turo_trips_normalized trips', 'trips.id = cases.turo_trip_normalized_id')
            ->join('fleet_vehicles vehicles', 'vehicles.id = cases.fleet_vehicle_id')
            ->where('cases.company_id', $companyId)
            ->orderBy('trips.ends_at', 'DESC')->get()->getResultArray();
    }

    /** @return list<array<string, mixed>> */
    public function sessionsForCase(int $companyId, int $tripId): array
    {
        return $this->db->table('charging_sessions sessions')
            ->select('sessions.*, COUNT(lines.id) AS line_count')
            ->join('fleet_vehicles vehicles', 'vehicles.id = sessions.fleet_vehicle_id')
            ->join('tesla_charging_line_items lines', 'lines.charging_session_id = sessions.id', 'left')
            ->where('vehicles.company_id', $companyId)
            ->where('sessions.turo_trip_normalized_id', $tripId)
            ->where('sessions.source_type', 'tesla_invoice_csv')
            ->where('sessions.deleted_at', null)
            ->groupBy('sessions.id')->orderBy('sessions.started_at', 'ASC')->get()->getResultArray();
    }

    /** @return list<array<string, mixed>> */
    public function lineItemsForTrip(int $companyId, int $tripId): array
    {
        return $this->db->table('tesla_charging_line_items lines')
            ->select('lines.*, sessions.custody_classification, sessions.custody_basis_code, sessions.custody_basis_event_id')
            ->join('charging_sessions sessions', 'sessions.id = lines.charging_session_id')
            ->join('fleet_vehicles vehicles', 'vehicles.id = sessions.fleet_vehicle_id')
            ->where('vehicles.company_id', $companyId)
            ->where('sessions.turo_trip_normalized_id', $tripId)
            ->where('sessions.deleted_at', null)
            ->orderBy('lines.started_at', 'ASC')->orderBy('lines.id', 'ASC')->get()->getResultArray();
    }

    /** @return list<array<string, mixed>> */
    public function reviewAndHostSessions(int $companyId): array
    {
        return $this->db->table('charging_sessions sessions')
            ->select('sessions.*, vehicles.fleet_code, vehicles.display_name AS vehicle_name, trips.turo_trip_id AS candidate_turo_trip_id')
            ->join('fleet_vehicles vehicles', 'vehicles.id = sessions.fleet_vehicle_id')
            ->join('turo_trips_normalized trips', 'trips.id = sessions.candidate_turo_trip_normalized_id', 'left')
            ->where('vehicles.company_id', $companyId)
            ->where('sessions.source_type', 'tesla_invoice_csv')
            ->where('sessions.deleted_at', null)
            ->whereIn('sessions.custody_classification', ['operator', 'between_trips', 'review'])
            ->orderBy('sessions.started_at', 'DESC')->get()->getResultArray();
    }

    /** @return list<array<string, mixed>> */
    public function unresolvedImportRows(int $companyId): array
    {
        return $this->db->table('tesla_charging_import_rows rows')
            ->select('rows.id, rows.row_number, rows.status_code, rows.issue_code, rows.issue_detail, batches.source_filename, batches.created_at')
            ->join('tesla_charging_import_batches batches', 'batches.id = rows.tesla_charging_import_batch_id')
            ->where('rows.company_id', $companyId)
            ->whereIn('rows.status_code', ['review', 'rejected'])
            ->orderBy('rows.id', 'DESC')->get()->getResultArray();
    }

    /** @return array<string, mixed>|null */
    public function caseForCompany(int $companyId, int $caseId): ?array
    {
        $row = $this->db->table('supercharger_reimbursement_cases')
            ->where(['company_id' => $companyId, 'id' => $caseId])->get()->getRowArray();

        return $row ?: null;
    }

    public function updateCaseWorkflow(int $companyId, int $caseId, string $state, ?string $note, ?string $reference, int $actorUserId): void
    {
        $case = $this->caseForCompany($companyId, $caseId);
        if ($case === null) {
            throw new \RuntimeException('Supercharger reimbursement case was not found for this company.');
        }
        $now = date('Y-m-d H:i:s');
        $new = [
            'workflow_state_code' => $state,
            'workflow_note' => $note,
            'invoice_reference' => $reference,
            'workflow_changed_by' => $actorUserId,
            'workflow_changed_at' => $now,
            'updated_at' => $now,
        ];
        $this->db->transException(true)->transStart();
        $this->db->table('supercharger_reimbursement_cases')->where(['company_id' => $companyId, 'id' => $caseId])->update($new);
        $this->db->table('operational_fact_audits')->insert([
            'company_id' => $companyId,
            'table_name' => 'supercharger_reimbursement_cases',
            'record_id' => $caseId,
            'action' => 'workflow_state_changed',
            'old_values' => json_encode($case, JSON_THROW_ON_ERROR),
            'new_values' => json_encode($new, JSON_THROW_ON_ERROR),
            'actor_user_id' => $actorUserId,
            'created_at' => $now,
        ]);
        $this->db->transComplete();
    }

    /** @return list<array<string, mixed>> */
    public function caseAudits(int $companyId, int $caseId): array
    {
        return $this->db->table('operational_fact_audits')
            ->where(['company_id' => $companyId, 'table_name' => 'supercharger_reimbursement_cases', 'record_id' => $caseId])
            ->orderBy('id', 'DESC')->get()->getResultArray();
    }

    private function eligibleTripsBase(int $companyId, int $vehicleId): \CodeIgniter\Database\BaseBuilder
    {
        return $this->db->table('turo_trips_normalized trips')
            ->select('trips.*, statuses.code AS trip_status_code')
            ->join('fleet_vehicles vehicles', 'vehicles.id = trips.fleet_vehicle_id')
            ->join('lookup_values statuses', 'statuses.id = trips.trip_status_lookup_value_id', 'left')
            ->where('vehicles.company_id', $companyId)
            ->where('trips.fleet_vehicle_id', $vehicleId)
            ->where('trips.deleted_at', null)
            ->where('trips.canceled_at', null)
            ->whereNotIn('statuses.code', ['invalid', 'canceled_zero_payout', 'canceled_host_payout']);
    }
}
