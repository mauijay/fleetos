<?php

namespace App\Repositories;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

class VehicleCapitalRepository
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /** @return array<string, mixed>|null */
    public function vehicle(int $vehicleId): ?array
    {
        $row = $this->db->table('fleet_vehicles fv')
            ->select('fv.*, vs.name AS status_name, vsp.model_year, vm.name AS model_name, vma.name AS make_name')
            ->join('vehicle_statuses vs', 'vs.id = fv.vehicle_status_id')
            ->join('vehicle_specs vsp', 'vsp.id = fv.vehicle_spec_id')
            ->join('vehicle_models vm', 'vm.id = vsp.vehicle_model_id')
            ->join('vehicle_makes vma', 'vma.id = vm.vehicle_make_id')
            ->where('fv.id', $vehicleId)
            ->where('fv.deleted_at', null)
            ->get()->getRowArray();

        return $row === null ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function acquisition(int $vehicleId): ?array
    {
        $row = $this->db->table('vehicle_acquisitions acquisition')
            ->select('acquisition.*, acquisition_method.code AS acquisition_method_code, acquisition_method.name AS acquisition_method_name')
            ->select('funding_method.code AS funding_method_code, funding_method.name AS funding_method_name')
            ->select('acquisition_loan.original_principal AS acquisition_loan_original_principal, acquisition_loan.loan_name AS acquisition_loan_name')
            ->select('acquisition_lender_company.name AS acquisition_lender_name')
            ->join('lookup_values acquisition_method', 'acquisition_method.id = acquisition.acquisition_method_lookup_value_id', 'left')
            ->join('lookup_values funding_method', 'funding_method.id = acquisition.funding_method_lookup_value_id', 'left')
            ->join('loans acquisition_loan', 'acquisition_loan.id = acquisition.acquisition_loan_id', 'left')
            ->join('lenders acquisition_lender', 'acquisition_lender.id = acquisition_loan.lender_id', 'left')
            ->join('companies acquisition_lender_company', 'acquisition_lender_company.id = acquisition_lender.company_id', 'left')
            ->where('acquisition.fleet_vehicle_id', $vehicleId)
            ->where('acquisition.deleted_at', null)
            ->get()->getRowArray();

        return $row === null ? null : $row;
    }

    /** @return array<int, array<string, mixed>> */
    public function loans(int $vehicleId): array
    {
        $loans = $this->db->table('loans loan')
            ->select('loan.*, company.name AS lender_name, status.code AS status_code, status.name AS status_name')
            ->join('lenders lender', 'lender.id = loan.lender_id')
            ->join('companies company', 'company.id = lender.company_id')
            ->join('lookup_values status', 'status.id = loan.loan_status_lookup_value_id', 'left')
            ->where('loan.fleet_vehicle_id', $vehicleId)
            ->where('loan.deleted_at', null)
            ->orderBy('loan.opened_on', 'DESC')
            ->orderBy('loan.id', 'DESC')
            ->get()->getResultArray();

        foreach ($loans as &$loan) {
            $snapshot = $this->db->table('loan_balance_snapshots snapshot')
                ->select('snapshot.id AS snapshot_id, snapshot.as_of_date, snapshot.principal_balance, snapshot.payoff_amount, snapshot.source_reference')
                ->select('source.code AS snapshot_source_code, source.name AS snapshot_source_name')
                ->join('lookup_values source', 'source.id = snapshot.source_method_lookup_value_id')
                ->where('snapshot.loan_id', (int) $loan['id'])
                ->orderBy('snapshot.as_of_date', 'DESC')
                ->orderBy('snapshot.id', 'DESC')
                ->get(1)->getRowArray();
            $loan = array_merge($loan, $snapshot ?? [
                'snapshot_id' => null,
                'as_of_date' => null,
                'principal_balance' => null,
                'payoff_amount' => null,
                'source_reference' => null,
                'snapshot_source_code' => null,
                'snapshot_source_name' => null,
            ]);
        }
        unset($loan);

        return $loans;
    }

    /** @return array<int, array<string, mixed>> */
    public function snapshots(int $loanId): array
    {
        return $this->db->table('loan_balance_snapshots snapshot')
            ->select('snapshot.*, source.code AS source_code, source.name AS source_name')
            ->join('lookup_values source', 'source.id = snapshot.source_method_lookup_value_id')
            ->where('snapshot.loan_id', $loanId)
            ->orderBy('snapshot.as_of_date', 'DESC')
            ->orderBy('snapshot.id', 'DESC')
            ->get()->getResultArray();
    }

    /** @return array<int, array<string, mixed>> */
    public function lenders(): array
    {
        return $this->db->table('lenders lender')
            ->select('lender.id, company.name, company.legal_name')
            ->join('companies company', 'company.id = lender.company_id')
            ->join('lookup_values company_type', 'company_type.id = company.company_type_lookup_value_id')
            ->where('company_type.code', 'lender')
            ->where('company.is_active', true)
            ->where('company.deleted_at', null)
            ->where('lender.deleted_at', null)
            ->orderBy('company.name', 'ASC')
            ->get()->getResultArray();
    }

    /** @return array<int, array<string, mixed>> */
    public function lookupValues(string $typeCode): array
    {
        return $this->db->table('lookup_values value')
            ->select('value.id, value.code, value.name')
            ->join('lookup_types type', 'type.id = value.lookup_type_id')
            ->where('type.code', $typeCode)
            ->where('value.is_active', true)
            ->orderBy('value.sort_order', 'ASC')
            ->get()->getResultArray();
    }

    /** @return array<int, array<string, mixed>> */
    public function startupCosts(int $vehicleId): array
    {
        return $this->db->table('startup_costs cost')
            ->select('cost.*, type.name AS cost_type_name')
            ->join('lookup_values type', 'type.id = cost.cost_type_lookup_value_id', 'left')
            ->where('cost.fleet_vehicle_id', $vehicleId)
            ->where('cost.deleted_at', null)
            ->orderBy('cost.incurred_on', 'DESC')
            ->get()->getResultArray();
    }

    /** @return array<int, array<string, mixed>> */
    public function vehicleNotes(int $vehicleId): array
    {
        return $this->db->table('vehicle_notes link')
            ->select('note.*, type.name AS note_type_name')
            ->join('notes note', 'note.id = link.note_id')
            ->join('lookup_values type', 'type.id = link.note_type_lookup_value_id', 'left')
            ->where('link.fleet_vehicle_id', $vehicleId)
            ->where('note.deleted_at', null)
            ->orderBy('note.noted_at', 'DESC')
            ->orderBy('note.id', 'DESC')
            ->get()->getResultArray();
    }

    /** @return array<int, array<string, mixed>> */
    public function vehicleFiles(int $vehicleId): array
    {
        return $this->db->table('vehicle_files link')
            ->select('file.*, type.code AS file_type_code, type.name AS file_type_name')
            ->join('files file', 'file.id = link.file_id')
            ->join('lookup_values type', 'type.id = link.file_type_lookup_value_id', 'left')
            ->where('link.fleet_vehicle_id', $vehicleId)
            ->where('file.deleted_at', null)
            ->orderBy('file.document_date', 'DESC')
            ->orderBy('file.id', 'DESC')
            ->get()->getResultArray();
    }

    public function lifetimeOperatingRevenue(int $vehicleId): string
    {
        $row = $this->db->table('turo_transactions_normalized')
            ->select('COALESCE(SUM(amount), 0) AS total', false)
            ->where('fleet_vehicle_id', $vehicleId)
            ->where('event_class', 'operating_revenue')
            ->get()->getRowArray();

        return number_format((float) ($row['total'] ?? 0), 2, '.', '');
    }

    /** @return array<string, mixed>|null */
    public function loan(int $loanId): ?array
    {
        $row = $this->db->table('loans')->where('id', $loanId)->where('deleted_at', null)->get()->getRowArray();

        return $row === null ? null : $row;
    }

    public function lenderIsValid(int $lenderId): bool
    {
        return $this->db->table('lenders lender')
            ->join('companies company', 'company.id = lender.company_id')
            ->join('lookup_values company_type', 'company_type.id = company.company_type_lookup_value_id')
            ->where('lender.id', $lenderId)
            ->where('lender.deleted_at', null)
            ->where('company.deleted_at', null)
            ->where('company.is_active', true)
            ->where('company_type.code', 'lender')
            ->countAllResults() === 1;
    }

    /** @return array<string, mixed>|null */
    public function snapshotForDate(int $loanId, string $asOfDate): ?array
    {
        $row = $this->db->table('loan_balance_snapshots')->where('loan_id', $loanId)->where('as_of_date', $asOfDate)->get()->getRowArray();

        return $row === null ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function snapshot(int $snapshotId): ?array
    {
        $row = $this->db->table('loan_balance_snapshots')->where('id', $snapshotId)->get()->getRowArray();

        return $row === null ? null : $row;
    }

    /** @param array<string, mixed> $data */
    public function insert(string $table, array $data): int
    {
        $this->db->table($table)->insert($data);

        return (int) $this->db->insertID();
    }

    /** @param array<string, mixed> $data */
    public function update(string $table, int $id, array $data): void
    {
        $this->db->table($table)->where('id', $id)->update($data);
    }
}
