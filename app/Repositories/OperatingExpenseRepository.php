<?php

namespace App\Repositories;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use RuntimeException;

class OperatingExpenseRepository
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /** @return list<array<string, mixed>> */
    public function categories(): array
    {
        return $this->db->table('lookup_values value')
            ->select('value.id, value.code, value.name')
            ->join('lookup_types type', 'type.id = value.lookup_type_id')
            ->where('type.code', 'operating_expense_category')
            ->where('value.is_active', true)
            ->orderBy('value.sort_order', 'ASC')
            ->get()->getResultArray();
    }

    /** @return array<string, mixed>|null */
    public function category(string $code): ?array
    {
        $row = $this->db->table('lookup_values value')
            ->select('value.id, value.code, value.name')
            ->join('lookup_types type', 'type.id = value.lookup_type_id')
            ->where('type.code', 'operating_expense_category')
            ->where('value.code', $code)
            ->where('value.is_active', true)
            ->get()->getRowArray();

        return $row === null ? null : $row;
    }

    /** @return list<array<string, mixed>> */
    public function vehicles(int $companyId): array
    {
        return $this->db->table('fleet_vehicles')
            ->select('id, fleet_number, fleet_code, display_name')
            ->where('company_id', $companyId)
            ->where('deleted_at', null)
            ->orderBy('sort_order', 'ASC')
            ->orderBy('id', 'ASC')
            ->get()->getResultArray();
    }

    /** @return array<string, mixed>|null */
    public function vehicle(int $companyId, int $vehicleId): ?array
    {
        $row = $this->db->table('fleet_vehicles')
            ->where(['company_id' => $companyId, 'id' => $vehicleId])
            ->where('deleted_at', null)
            ->get()->getRowArray();

        return $row === null ? null : $row;
    }

    /** @return list<array<string, mixed>> */
    public function trips(int $companyId, int $limit = 100): array
    {
        return $this->db->table('turo_trips_normalized trip')
            ->select('trip.id, trip.turo_trip_id, trip.guest_name, trip.starts_at, trip.fleet_vehicle_id')
            ->select('vehicle.fleet_number, vehicle.fleet_code, vehicle.display_name')
            ->join('fleet_vehicles vehicle', 'vehicle.id = trip.fleet_vehicle_id')
            ->where('vehicle.company_id', $companyId)
            ->where('vehicle.deleted_at', null)
            ->where('trip.deleted_at', null)
            ->orderBy('trip.starts_at', 'DESC')
            ->orderBy('trip.id', 'DESC')
            ->limit($limit)
            ->get()->getResultArray();
    }

    /** @return array<string, mixed>|null */
    public function trip(int $companyId, int $tripId): ?array
    {
        $row = $this->db->table('turo_trips_normalized trip')
            ->select('trip.*, vehicle.company_id')
            ->join('fleet_vehicles vehicle', 'vehicle.id = trip.fleet_vehicle_id')
            ->where('trip.id', $tripId)
            ->where('vehicle.company_id', $companyId)
            ->where('vehicle.deleted_at', null)
            ->where('trip.deleted_at', null)
            ->get()->getRowArray();

        return $row === null ? null : $row;
    }

    /** @param array<string, mixed> $data */
    public function createExpense(array $data): int
    {
        $this->db->table('operating_expenses')->insert($data);

        return (int) $this->db->insertID();
    }

    /** @param array<string, mixed> $data */
    public function updateExpense(int $companyId, int $id, array $data): bool
    {
        $this->db->table('operating_expenses')->where(['company_id' => $companyId, 'id' => $id])->update($data);

        return $this->db->affectedRows() === 1;
    }

    /** @return array<string, mixed>|null */
    public function expense(int $companyId, int $id): ?array
    {
        $row = $this->expenseBuilder($companyId)->where('expense.id', $id)->get()->getRowArray();

        return $row === null ? null : $row;
    }

    /** @param array<string, mixed> $data */
    public function createReceipt(array $data): int
    {
        $this->db->table('operating_expense_receipts')->insert($data);

        return (int) $this->db->insertID();
    }

    /** @param array<string, mixed> $data */
    public function updateReceipt(int $companyId, int $id, array $data): bool
    {
        $this->db->table('operating_expense_receipts')->where(['company_id' => $companyId, 'id' => $id])->update($data);

        return $this->db->affectedRows() === 1;
    }

    /** @return array<string, mixed>|null */
    public function receipt(int $companyId, int $id): ?array
    {
        $row = $this->receiptBuilder($companyId)->where('receipt.id', $id)->get()->getRowArray();

        return $row === null ? null : $row;
    }

    /** @return list<array<string, mixed>> */
    public function receiptsForExpense(int $companyId, int $expenseId): array
    {
        return $this->receiptBuilder($companyId)
            ->where('receipt.operating_expense_id', $expenseId)
            ->orderBy('receipt.created_at', 'ASC')
            ->orderBy('receipt.id', 'ASC')
            ->get()->getResultArray();
    }

    /** @return array<string, mixed>|null */
    public function receiptForCompanyFile(int $companyId, int $fileId): ?array
    {
        $row = $this->receiptBuilder($companyId)->where('receipt.file_id', $fileId)->get()->getRowArray();

        return $row === null ? null : $row;
    }

    /** @return array{rows:list<array<string,mixed>>,total:int} */
    public function expensePage(int $companyId, string $view, array $filters, int $page, int $perPage): array
    {
        $builder = $this->expenseBuilder($companyId);
        if ($view === 'history') {
            $builder->where('expense.status_code', 'archived');
        } else {
            $builder->where('expense.status_code', 'recorded');
        }
        $this->applyExpenseFilters($builder, $filters);
        $totalBuilder = clone $builder;
        $total = $totalBuilder->countAllResults();
        if ($view === 'vehicle') {
            $builder->orderBy('expense.fleet_vehicle_id', 'ASC');
        }
        $rows = $builder
            ->orderBy('expense.expense_date', 'DESC')
            ->orderBy('expense.id', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->get()->getResultArray();

        return ['rows' => $rows, 'total' => $total];
    }

    /** @return array{rows:list<array<string,mixed>>,total:int} */
    public function receiptPage(int $companyId, string $view, int $page, int $perPage): array
    {
        $builder = $this->receiptBuilder($companyId);
        if ($view === 'needs_attention') {
            $builder->where('receipt.classification_code', 'needs_classification')->where('receipt.archived_at', null);
        } elseif ($view === 'history') {
            $builder->groupStart()
                ->whereIn('receipt.classification_code', ['non_business', 'duplicate', 'archived'])
                ->orWhere('receipt.archived_at IS NOT NULL')
                ->groupEnd();
        } else {
            return ['rows' => [], 'total' => 0];
        }
        $totalBuilder = clone $builder;
        $total = $totalBuilder->countAllResults();
        $rows = $builder->orderBy('receipt.created_at', 'DESC')->orderBy('receipt.id', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)->get()->getResultArray();

        return ['rows' => $rows, 'total' => $total];
    }

    public function recordedTotal(int $companyId): string
    {
        if (! $this->db->tableExists('operating_expenses')) {
            return '0.00';
        }
        $row = $this->db->table('operating_expenses')
            ->select('COALESCE(SUM(amount), 0) AS total', false)
            ->where(['company_id' => $companyId, 'status_code' => 'recorded'])
            ->get()->getRowArray();

        $total = (string) ($row['total'] ?? '0');
        if (preg_match('/^(-?\d+)(?:\.(\d+))?$/', $total, $matches) !== 1) {
            return '0.00';
        }

        return $matches[1] . '.' . substr(str_pad($matches[2] ?? '', 2, '0'), 0, 2);
    }

    /** @return list<array<string, mixed>> */
    public function recordedFinancialActivity(int $companyId, string $fromDate, string $toDateExclusive): array
    {
        return $this->db->table('operating_expenses expense')
            ->select('expense.id, expense.company_id, expense.fleet_vehicle_id, expense.turo_trip_normalized_id')
            ->select('expense.expense_date, expense.amount, expense.business_purpose, expense.vendor, category.code AS category_code')
            ->join('lookup_values category', 'category.id = expense.expense_category_lookup_value_id', 'left')
            ->where('expense.company_id', $companyId)
            ->where('expense.status_code', 'recorded')
            ->where('expense.archived_at', null)
            ->where('expense.amount >', 0)
            ->where('expense.expense_date >=', $fromDate)
            ->where('expense.expense_date <', $toDateExclusive)
            ->orderBy('expense.id', 'ASC')
            ->get()->getResultArray();
    }

    public function needsClassificationCount(int $companyId): int
    {
        if (! $this->db->tableExists('operating_expense_receipts')) {
            return 0;
        }

        return $this->db->table('operating_expense_receipts')
            ->where(['company_id' => $companyId, 'classification_code' => 'needs_classification', 'archived_at' => null])
            ->countAllResults();
    }

    /** @return list<array<string, mixed>> */
    public function possibleExpenseDuplicates(int $companyId, string $date, string $amount, int $categoryId, ?string $vendor, ?int $exceptId = null): array
    {
        $builder = $this->db->table('operating_expenses expense')
            ->select('expense.id, expense.expense_date, expense.amount, expense.vendor')
            ->where([
                'expense.company_id' => $companyId,
                'expense.status_code' => 'recorded',
                'expense.expense_date' => $date,
                'expense.amount' => $amount,
                'expense.expense_category_lookup_value_id' => $categoryId,
            ]);
        $vendor === null ? $builder->where('expense.vendor', null) : $builder->where('expense.vendor', $vendor);
        if ($exceptId !== null) {
            $builder->where('expense.id !=', $exceptId);
        }

        return $builder->orderBy('expense.id', 'DESC')->limit(5)->get()->getResultArray();
    }

    public function transaction(callable $callback): mixed
    {
        $this->db->transBegin();
        try {
            $result = $callback();
            if ($this->db->transStatus() === false) {
                throw new RuntimeException('Operating expense transaction failed.');
            }
            $this->db->transCommit();

            return $result;
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }

    private function expenseBuilder(int $companyId): BaseBuilder
    {
        return $this->db->table('operating_expenses expense')
            ->select('expense.*, category.code AS category_code, category.name AS category_name')
            ->select('vehicle.fleet_number, vehicle.fleet_code, vehicle.display_name')
            ->select('trip.turo_trip_id, trip.guest_name, trip.starts_at AS trip_starts_at')
            ->select('(SELECT COUNT(*) FROM ' . $this->db->prefixTable('operating_expense_receipts') . ' linked_receipt WHERE linked_receipt.operating_expense_id = expense.id AND linked_receipt.company_id = expense.company_id) AS receipt_count', false)
            ->select('(SELECT MIN(linked_receipt.id) FROM ' . $this->db->prefixTable('operating_expense_receipts') . ' linked_receipt WHERE linked_receipt.operating_expense_id = expense.id AND linked_receipt.company_id = expense.company_id) AS first_receipt_id', false)
            ->join('lookup_values category', 'category.id = expense.expense_category_lookup_value_id')
            ->join('fleet_vehicles vehicle', 'vehicle.id = expense.fleet_vehicle_id', 'left')
            ->join('turo_trips_normalized trip', 'trip.id = expense.turo_trip_normalized_id', 'left')
            ->where('expense.company_id', $companyId);
    }

    private function receiptBuilder(int $companyId): BaseBuilder
    {
        return $this->db->table('operating_expense_receipts receipt')
            ->select('receipt.*')
            ->select('file.storage_disk AS file_storage_disk, file.path AS file_path, file.original_filename AS file_original_filename')
            ->select('file.mime_type AS file_mime_type, file.size_bytes AS file_size_bytes, file.checksum AS file_checksum, file.deleted_at AS file_deleted_at')
            ->select('duplicate_file.original_filename AS duplicate_original_filename')
            ->join('files file', 'file.id = receipt.file_id')
            ->join('operating_expense_receipts duplicate_receipt', 'duplicate_receipt.id = receipt.duplicate_of_receipt_id', 'left')
            ->join('files duplicate_file', 'duplicate_file.id = duplicate_receipt.file_id', 'left')
            ->where('receipt.company_id', $companyId);
    }

    /** @param array<string, mixed> $filters */
    private function applyExpenseFilters(BaseBuilder $builder, array $filters): void
    {
        if (($filters['category'] ?? '') !== '') {
            $builder->where('category.code', $filters['category']);
        }
        if (($filters['vehicle'] ?? '') === 'fleet') {
            $builder->where('expense.fleet_vehicle_id', null);
        } elseif ((int) ($filters['vehicle'] ?? 0) > 0) {
            $builder->where('expense.fleet_vehicle_id', (int) $filters['vehicle']);
        }
        if (($filters['source'] ?? '') !== '') {
            $builder->where('expense.source_code', $filters['source']);
        }
        if (($filters['from'] ?? '') !== '') {
            $builder->where('expense.expense_date >=', $filters['from']);
        }
        if (($filters['to'] ?? '') !== '') {
            $builder->where('expense.expense_date <=', $filters['to']);
        }
    }
}
