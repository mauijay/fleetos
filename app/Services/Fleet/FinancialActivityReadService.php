<?php

namespace App\Services\Fleet;

use App\Repositories\ChargingCostRepository;
use App\Repositories\MaintenanceCostRepository;
use App\Repositories\OperatingExpenseRepository;
use App\Repositories\TripMonthAllocationRepository;
use App\Repositories\TuroAccessReimbursementRepository;
use App\Repositories\TuroNormalizedTransactionRepository;
use App\Services\Turo\TuroEarningsAmountResolver;
use Config\Services;
use DateTimeImmutable;
use DateTimeZone;

class FinancialActivityReadService
{
    /** @var list<string> */
    private array $validatedRecoveryTransactionTypes;

    /**
     * Production intentionally supplies an empty recovery allowlist until an
     * exact real Turo source label has fixture-backed semantic proof.
     *
     * @param list<string> $validatedRecoveryTransactionTypes
     */
    public function __construct(
        private readonly ?TuroNormalizedTransactionRepository $transactions = null,
        private readonly ?TripMonthAllocationRepository $allocations = null,
        private readonly ?OperatingExpenseRepository $operatingExpenses = null,
        private readonly ?MaintenanceCostRepository $maintenance = null,
        private readonly ?ChargingCostRepository $charging = null,
        private readonly ?TuroAccessReimbursementRepository $airportExpenses = null,
        private readonly TuroEarningsAmountResolver $amountResolver = new TuroEarningsAmountResolver(),
        array $validatedRecoveryTransactionTypes = [],
    ) {
        $this->validatedRecoveryTransactionTypes = array_values(array_unique(array_filter(
            array_map(static fn (string $type): string => trim($type), $validatedRecoveryTransactionTypes),
            static fn (string $type): bool => $type !== '',
        )));
    }

    /**
     * @return array{
     *   activities:list<array<string,mixed>>,
     *   diagnostics:array{source_query_count:int,unsafe_turo_rows_excluded:int,unvalidated_recovery_rows_excluded:int,validated_recovery_transaction_types:list<string>,invalid_airport_allocations_excluded:int}
     * }
     */
    public function forPeriod(int $companyId, string $fromDate, string $toDateExclusive): array
    {
        if ($companyId < 1) {
            throw new \InvalidArgumentException('An active company is required for financial reporting.');
        }

        $activities = [];
        $unsafeTuroRows = 0;
        $unvalidatedRecoveries = 0;
        $invalidAirportAllocations = 0;

        foreach ($this->transactionRepository()->financialActivityForCompany($companyId, $fromDate, $toDateExclusive) as $row) {
            if ($this->hasUnsafePersistedSign($row)) {
                $unsafeTuroRows++;
                continue;
            }

            $eventClass = (string) ($row['event_class'] ?? '');
            if ($eventClass === 'reimbursement' && ! in_array((string) ($row['transaction_type'] ?? ''), $this->validatedRecoveryTransactionTypes, true)) {
                $unvalidatedRecoveries++;
                continue;
            }
            if (! in_array($eventClass, ['operating_revenue', 'reimbursement'], true)) {
                continue;
            }

            $activities[] = $this->activity(
                sourceType: 'turo_transaction',
                sourceId: (int) $row['id'],
                companyId: $companyId,
                vehicleId: $this->nullableId($row['fleet_vehicle_id'] ?? null),
                tripId: $this->nullableId($row['turo_trip_normalized_id'] ?? null),
                occurredOn: (string) $row['transaction_date'],
                amount: (string) $row['amount'],
                category: $eventClass,
                description: (string) ($row['description'] ?? $row['transaction_type'] ?? 'Turo transaction'),
                recognitionBasis: 'realized',
                allocationState: ($row['turo_trip_normalized_id'] ?? null) === null ? 'direct_vehicle' : 'trip_derived',
                financialGroup: $eventClass === 'reimbursement' ? 'realized_recoveries' : 'realized_operating_revenue',
            );
        }

        foreach ($this->allocationRepository()->forecastActivityForCompany($companyId, $fromDate, $toDateExclusive) as $row) {
            $activities[] = $this->activity(
                'trip_month_allocation',
                (int) $row['id'],
                $companyId,
                $this->nullableId($row['fleet_vehicle_id'] ?? null),
                $this->nullableId($row['turo_trip_normalized_id'] ?? null),
                (string) $row['allocation_month'],
                (string) $row['allocated_host_payout_amount'],
                'host_payout',
                'Forecast host payout',
                'forecast',
                'trip_month_allocation',
                'forecast_host_payout',
            );
        }

        foreach ($this->operatingExpenseRepository()->recordedFinancialActivity($companyId, $fromDate, $toDateExclusive) as $row) {
            $activities[] = $this->activity(
                'operating_expense',
                (int) $row['id'],
                $companyId,
                $this->nullableId($row['fleet_vehicle_id'] ?? null),
                $this->nullableId($row['turo_trip_normalized_id'] ?? null),
                (string) $row['expense_date'],
                (string) $row['amount'],
                (string) ($row['category_code'] ?? 'other'),
                trim((string) ($row['business_purpose'] ?? '')) ?: trim((string) ($row['vendor'] ?? '')) ?: 'Recorded operating expense',
                'recorded_incurred',
                ($row['fleet_vehicle_id'] ?? null) === null ? 'fleet_wide' : 'direct_vehicle',
                'recorded_operating_costs',
                '/operations/expenses/' . (int) $row['id'],
            );
        }

        foreach ($this->maintenanceRepository()->recordedActivityForCompany($companyId, $fromDate, $toDateExclusive) as $row) {
            $activities[] = $this->activity(
                'maintenance_log',
                (int) $row['id'],
                $companyId,
                (int) $row['fleet_vehicle_id'],
                null,
                (string) $row['service_on'],
                (string) $row['total_amount'],
                'maintenance',
                trim((string) ($row['description'] ?? '')) ?: 'Recorded completed maintenance cost',
                'recorded_incurred',
                'direct_vehicle',
                'recorded_operating_costs',
            );
        }

        foreach ($this->chargingRepository()->recordedActivityForCompany(
            $companyId,
            $this->businessBoundary($fromDate),
            $this->businessBoundary($toDateExclusive),
        ) as $row) {
            $activities[] = $this->activity(
                'charging_session',
                (int) $row['id'],
                $companyId,
                (int) $row['fleet_vehicle_id'],
                $this->nullableId($row['turo_trip_normalized_id'] ?? null),
                (new DateTimeImmutable((string) $row['ended_at'], new DateTimeZone('Pacific/Honolulu')))
                    ->setTimezone(new DateTimeZone('Pacific/Honolulu'))
                    ->format('Y-m-d'),
                (string) $row['cost_amount'],
                'charging',
                trim((string) ($row['charging_location'] ?? '')) ?: 'Recorded charging cost',
                'recorded_incurred',
                ($row['turo_trip_normalized_id'] ?? null) === null ? 'direct_vehicle' : 'trip_derived',
                'recorded_operating_costs',
            );
        }

        $airportExpenses = [];
        foreach ($this->airportExpenseRepository()->recordedOperatingExpenseActivity($companyId, $fromDate, $toDateExclusive) as $row) {
            $expenseId = (int) $row['id'];
            if (! isset($airportExpenses[$expenseId])) {
                $airportExpenses[$expenseId] = $this->activity(
                    'airport_operations_expense',
                    $expenseId,
                    $companyId,
                    null,
                    null,
                    (string) $row['expense_date'],
                    (string) $row['amount'],
                    (string) ($row['expense_category'] ?? 'other'),
                    trim((string) ($row['business_purpose_note'] ?? '')) ?: 'Recorded airport operating expense',
                    'recorded_incurred',
                    'fleet_wide',
                    'recorded_operating_costs',
                    '/operations/airport/reimbursements',
                );
                $airportExpenses[$expenseId]['vehicle_allocations'] = [];
                $airportExpenses[$expenseId]['allocation_total_cents'] = 0;
            }

            $allocationId = $this->nullableId($row['allocation_id'] ?? null);
            if ($allocationId === null) {
                continue;
            }
            $vehicleId = $this->nullableId($row['allocation_fleet_vehicle_id'] ?? null);
            $allocatedCents = $this->decimalToCents((string) ($row['allocated_amount'] ?? '0'));
            $airportExpenses[$expenseId]['allocation_total_cents'] += max(0, $allocatedCents);
            if ($vehicleId === null) {
                if ((string) ($row['allocation_method'] ?? '') !== 'unallocated') {
                    $invalidAirportAllocations++;
                }
                continue;
            }
            if ((int) ($row['allocation_vehicle_company_id'] ?? 0) !== $companyId || $allocatedCents <= 0) {
                $invalidAirportAllocations++;
                continue;
            }

            $airportExpenses[$expenseId]['vehicle_allocations'][] = $this->activity(
                'airport_operations_expense_allocation',
                $allocationId,
                $companyId,
                $vehicleId,
                null,
                (string) $row['expense_date'],
                $this->centsToDecimal($allocatedCents),
                (string) ($row['expense_category'] ?? 'other'),
                trim((string) ($row['business_purpose_note'] ?? '')) ?: 'Recorded airport operating expense allocation',
                'recorded_incurred',
                'explicit_vehicle_allocation',
                'recorded_operating_costs',
                '/operations/airport/reimbursements',
            );
        }

        foreach ($airportExpenses as $airportExpense) {
            $sourceCents = $this->decimalToCents((string) $airportExpense['amount']);
            $allocatedCents = array_sum(array_map(
                fn (array $allocation): int => $this->decimalToCents((string) $allocation['amount']),
                $airportExpense['vehicle_allocations'],
            ));
            if ($airportExpense['allocation_total_cents'] > $sourceCents) {
                $invalidAirportAllocations += count($airportExpense['vehicle_allocations']);
                $airportExpense['vehicle_allocations'] = [];
                $allocatedCents = 0;
            }
            unset($airportExpense['allocation_total_cents']);
            $airportExpense['allocation_state'] = match (true) {
                $allocatedCents === 0 => 'fleet_wide',
                $allocatedCents === $sourceCents => 'fully_allocated',
                default => 'partially_allocated',
            };
            $activities[] = $airportExpense;
        }

        return [
            'activities' => $activities,
            'diagnostics' => [
                'source_query_count' => 6,
                'unsafe_turo_rows_excluded' => $unsafeTuroRows,
                'unvalidated_recovery_rows_excluded' => $unvalidatedRecoveries,
                'validated_recovery_transaction_types' => $this->validatedRecoveryTransactionTypes,
                'invalid_airport_allocations_excluded' => $invalidAirportAllocations,
            ],
        ];
    }

    /** @param array<string, mixed> $row */
    private function hasUnsafePersistedSign(array $row): bool
    {
        $payload = json_decode((string) ($row['raw_payload'] ?? ''), true);
        if (! is_array($payload)) {
            return false;
        }
        $resolved = $this->amountResolver->resolve($payload);
        if ($resolved === null || $resolved->parsedValue === null) {
            return false;
        }

        return number_format((float) $resolved->parsedValue, 2, '.', '') !== number_format((float) ($row['amount'] ?? 0), 2, '.', '');
    }

    /** @return array<string, mixed> */
    private function activity(
        string $sourceType,
        int $sourceId,
        int $companyId,
        ?int $vehicleId,
        ?int $tripId,
        string $occurredOn,
        string $amount,
        string $category,
        string $description,
        string $recognitionBasis,
        string $allocationState,
        string $financialGroup,
        ?string $sourceLink = null,
    ): array {
        return [
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'company_id' => $companyId,
            'fleet_vehicle_id' => $vehicleId,
            'turo_trip_normalized_id' => $tripId,
            'occurred_on' => $occurredOn,
            'amount' => number_format((float) $amount, 2, '.', ''),
            'category' => $category,
            'description' => $description,
            'recognition_basis' => $recognitionBasis,
            'allocation_state' => $allocationState,
            'financial_group' => $financialGroup,
            'source_link' => $sourceLink,
        ];
    }

    private function nullableId(mixed $value): ?int
    {
        $id = (int) ($value ?? 0);

        return $id > 0 ? $id : null;
    }

    private function businessBoundary(string $date): string
    {
        return (new DateTimeImmutable($date, new DateTimeZone('Pacific/Honolulu')))->setTime(0, 0)->format('Y-m-d H:i:s');
    }

    private function decimalToCents(string $amount): int
    {
        $amount = trim($amount);
        if (preg_match('/^(-?)(\d+)(?:\.(\d{1,2}))?$/', $amount, $matches) !== 1) {
            throw new \UnexpectedValueException('Airport allocation contains an invalid decimal amount.');
        }
        $cents = ((int) $matches[2] * 100) + (int) str_pad($matches[3] ?? '', 2, '0');

        return $matches[1] === '-' ? -$cents : $cents;
    }

    private function centsToDecimal(int $cents): string
    {
        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }

    private function transactionRepository(): TuroNormalizedTransactionRepository
    {
        return $this->transactions ?? Services::turoNormalizedTransactionRepository();
    }

    private function allocationRepository(): TripMonthAllocationRepository
    {
        return $this->allocations ?? Services::tripMonthAllocationRepository();
    }

    private function operatingExpenseRepository(): OperatingExpenseRepository
    {
        return $this->operatingExpenses ?? Services::operatingExpenseRepository();
    }

    private function maintenanceRepository(): MaintenanceCostRepository
    {
        return $this->maintenance ?? Services::maintenanceCostRepository();
    }

    private function chargingRepository(): ChargingCostRepository
    {
        return $this->charging ?? Services::chargingCostRepository();
    }

    private function airportExpenseRepository(): TuroAccessReimbursementRepository
    {
        return $this->airportExpenses ?? Services::turoAccessReimbursementRepository();
    }
}
