<?php

namespace App\Services\Fleet;

use App\Repositories\FleetVehicleRepository;
use App\Repositories\TuroNormalizedTransactionRepository;
use Config\Services;
use DateTimeImmutable;
use DateTimeZone;
use UnexpectedValueException;

class VehiclePerformanceReportService
{
    private const SORTS = ['vehicle', 'in_service_date', 'mtd', 'ytd', 'lifetime'];
    private const DIRECTIONS = ['asc', 'desc'];

    public function __construct(
        private readonly ?FleetVehicleRepository $vehicleRepository = null,
        private readonly ?TuroNormalizedTransactionRepository $transactionRepository = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function report(
        int $companyId,
        string $asOfDate,
        string $sort = 'vehicle',
        string $direction = 'asc',
    ): array {
        $asOf = DateTimeImmutable::createFromFormat('!Y-m-d', $asOfDate, new DateTimeZone('Pacific/Honolulu'));
        if ($asOf === false || $asOf->format('Y-m-d') !== $asOfDate) {
            throw new UnexpectedValueException('Vehicle performance reporting date must be a valid Honolulu calendar date.');
        }

        $sort = in_array($sort, self::SORTS, true) ? $sort : 'vehicle';
        $direction = in_array($direction, self::DIRECTIONS, true) ? $direction : 'asc';
        $monthStart = $asOf->modify('first day of this month')->format('Y-m-d');
        $yearStart = $asOf->setDate((int) $asOf->format('Y'), 1, 1)->format('Y-m-d');
        $toDateExclusive = $asOf->modify('+1 day')->format('Y-m-d');
        $rowsByVehicle = [];

        foreach ($this->vehicles()->financialReportRoster($companyId) as $vehicle) {
            $vehicleId = (int) $vehicle['id'];
            $rowsByVehicle[$vehicleId] = [
                'fleet_vehicle_id' => $vehicleId,
                'fleet_number' => $this->nullableInt($vehicle['fleet_number'] ?? null),
                'fleet_code' => (string) ($vehicle['fleet_code'] ?? ''),
                'display_name' => (string) ($vehicle['display_name'] ?? ''),
                'vehicle_label' => $this->vehicleLabel($vehicle),
                'in_service_date' => $this->nullableDate($vehicle['in_service_date'] ?? null),
                'mtd_earnings_cents' => 0,
                'ytd_earnings_cents' => 0,
                'lifetime_earnings_cents' => 0,
                'is_unallocated' => false,
            ];
        }

        $unallocated = [
            'fleet_vehicle_id' => null,
            'fleet_number' => null,
            'fleet_code' => '',
            'display_name' => 'Unallocated / Unmatched',
            'vehicle_label' => 'Unallocated / Unmatched',
            'in_service_date' => null,
            'mtd_earnings_cents' => 0,
            'ytd_earnings_cents' => 0,
            'lifetime_earnings_cents' => 0,
            'is_unallocated' => true,
        ];

        foreach ($this->transactions()->operatingRevenuePerformanceForCompany($companyId, $monthStart, $yearStart, $toDateExclusive) as $earnings) {
            $vehicleId = (int) ($earnings['fleet_vehicle_id'] ?? 0);
            $target = isset($rowsByVehicle[$vehicleId]) ? $vehicleId : null;
            $values = [
                'mtd_earnings_cents' => $this->decimalToCents((string) ($earnings['mtd_earnings'] ?? '0')),
                'ytd_earnings_cents' => $this->decimalToCents((string) ($earnings['ytd_earnings'] ?? '0')),
                'lifetime_earnings_cents' => $this->decimalToCents((string) ($earnings['lifetime_earnings'] ?? '0')),
            ];
            foreach ($values as $metric => $cents) {
                if ($target === null) {
                    $unallocated[$metric] += $cents;
                } else {
                    $rowsByVehicle[$target][$metric] += $cents;
                }
            }
        }

        $rows = array_values($rowsByVehicle);
        $this->sortRows($rows, $sort, $direction);
        $hasUnallocated = $unallocated['mtd_earnings_cents'] !== 0
            || $unallocated['ytd_earnings_cents'] !== 0
            || $unallocated['lifetime_earnings_cents'] !== 0;
        if ($hasUnallocated) {
            $rows[] = $unallocated;
        }

        $totals = ['mtd_earnings_cents' => 0, 'ytd_earnings_cents' => 0, 'lifetime_earnings_cents' => 0];
        foreach ($rows as &$row) {
            foreach (array_keys($totals) as $metric) {
                $totals[$metric] += $row[$metric];
                $row[str_replace('_cents', '', $metric)] = $this->centsToFloat($row[$metric]);
            }
        }
        unset($row);

        return [
            'company_id' => $companyId,
            'as_of_date' => $asOfDate,
            'month_start' => $monthStart,
            'year_start' => $yearStart,
            'to_date_exclusive' => $toDateExclusive,
            'sort' => $sort,
            'direction' => $direction,
            'rows' => $rows,
            'vehicle_count' => count($rowsByVehicle),
            'has_unallocated' => $hasUnallocated,
            'totals' => [
                'mtd_earnings' => $this->centsToFloat($totals['mtd_earnings_cents']),
                'ytd_earnings' => $this->centsToFloat($totals['ytd_earnings_cents']),
                'lifetime_earnings' => $this->centsToFloat($totals['lifetime_earnings_cents']),
            ],
            'reconciliation' => [
                'mtd_difference' => 0.0,
                'ytd_difference' => 0.0,
                'lifetime_difference' => 0.0,
            ],
            'diagnostics' => [
                'vehicle_roster_query_count' => 1,
                'revenue_aggregation_query_count' => 1,
                'total_query_count' => 2,
            ],
        ];
    }

    /** @param list<array<string, mixed>> $rows */
    private function sortRows(array &$rows, string $sort, string $direction): void
    {
        usort($rows, function (array $left, array $right) use ($sort, $direction): int {
            $comparison = match ($sort) {
                'in_service_date' => $this->dateOrder($left['in_service_date'], $right['in_service_date'], $direction),
                'mtd' => $left['mtd_earnings_cents'] <=> $right['mtd_earnings_cents'],
                'ytd' => $left['ytd_earnings_cents'] <=> $right['ytd_earnings_cents'],
                'lifetime' => $left['lifetime_earnings_cents'] <=> $right['lifetime_earnings_cents'],
                default => $this->vehicleOrder($left, $right),
            };
            if ($comparison === 0) {
                return $this->vehicleOrder($left, $right);
            }
            if ($sort === 'in_service_date') {
                return $comparison;
            }

            return $direction === 'desc' ? -$comparison : $comparison;
        });
    }

    private function dateOrder(?string $left, ?string $right, string $direction): int
    {
        if ($left === null || $right === null) {
            return $left === $right ? 0 : ($left === null ? 1 : -1);
        }
        $comparison = $left <=> $right;

        return $direction === 'desc' ? -$comparison : $comparison;
    }

    /** @param array<string, mixed> $left @param array<string, mixed> $right */
    private function vehicleOrder(array $left, array $right): int
    {
        $number = ($left['fleet_number'] ?? PHP_INT_MAX) <=> ($right['fleet_number'] ?? PHP_INT_MAX);
        if ($number !== 0) {
            return $number;
        }
        $code = strnatcasecmp((string) $left['fleet_code'], (string) $right['fleet_code']);

        return $code !== 0 ? $code : (int) $left['fleet_vehicle_id'] <=> (int) $right['fleet_vehicle_id'];
    }

    /** @param array<string, mixed> $vehicle */
    private function vehicleLabel(array $vehicle): string
    {
        foreach (['display_name', 'fleet_code'] as $field) {
            $label = trim((string) ($vehicle[$field] ?? ''));
            if ($label !== '') {
                return $label;
            }
        }
        $fleetNumber = $this->nullableInt($vehicle['fleet_number'] ?? null);

        return $fleetNumber === null ? 'Vehicle #' . (int) $vehicle['id'] : 'Fleet #' . $fleetNumber;
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    private function nullableDate(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }

    private function decimalToCents(string $amount): int
    {
        $amount = trim($amount);
        if (preg_match('/^(-?)(\d+)(?:\.(\d{1,2}))?$/', $amount, $matches) !== 1) {
            throw new UnexpectedValueException('Vehicle performance revenue contains an invalid decimal amount.');
        }
        $cents = ((int) $matches[2] * 100) + (int) str_pad($matches[3] ?? '', 2, '0');

        return $matches[1] === '-' ? -$cents : $cents;
    }

    private function centsToFloat(int $cents): float
    {
        return round($cents / 100, 2);
    }

    private function vehicles(): FleetVehicleRepository
    {
        return $this->vehicleRepository ?? Services::fleetVehicleRepository();
    }

    private function transactions(): TuroNormalizedTransactionRepository
    {
        return $this->transactionRepository ?? Services::turoNormalizedTransactionRepository();
    }
}
