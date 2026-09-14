<?php

namespace App\Services\Fleet;

use App\Repositories\FleetVehicleRepository;
use Config\Services;

class VehicleFinancialSummaryService
{
    private const SORTS = ['net', 'vehicle', 'revenue', 'recoveries', 'costs'];
    private const DIRECTIONS = ['asc', 'desc'];

    public function __construct(
        private readonly ?FinancialSummaryService $financialSummaryService = null,
        private readonly ?FleetVehicleRepository $vehicleRepository = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function period(int $companyId, string $fromDate, string $toDateExclusive, string $sort = 'net', string $direction = 'desc'): array
    {
        $sort = in_array($sort, self::SORTS, true) ? $sort : 'net';
        $direction = in_array($direction, self::DIRECTIONS, true) ? $direction : ($sort === 'vehicle' ? 'asc' : 'desc');
        $fleet = $this->financialSummary()->period($companyId, $fromDate, $toDateExclusive);
        $vehicles = [];

        foreach ($this->vehicles()->financialReportRoster($companyId) as $vehicle) {
            $id = (int) $vehicle['id'];
            $vehicles[$id] = [
                'fleet_vehicle_id' => $id,
                'fleet_number' => $this->nullableInt($vehicle['fleet_number'] ?? null),
                'fleet_code' => (string) ($vehicle['fleet_code'] ?? ''),
                'display_name' => (string) ($vehicle['display_name'] ?? ''),
                'vehicle_label' => $this->vehicleLabel($vehicle),
                'realized_operating_revenue_cents' => 0,
                'realized_recoveries_cents' => 0,
                'recorded_operating_costs_cents' => 0,
                'activities' => [],
            ];
        }

        $unallocatedCostCents = 0;
        $unallocatedRevenueCents = 0;
        $unallocatedRecoveryCents = 0;
        $unallocatedActivities = [];

        foreach ($fleet['activities'] as $activity) {
            $group = (string) ($activity['financial_group'] ?? '');
            if ($group === 'forecast_host_payout') {
                continue;
            }
            $amountCents = $this->decimalToCents((string) ($activity['amount'] ?? '0'));

            if ($group === 'recorded_operating_costs' && $activity['source_type'] === 'airport_operations_expense') {
                $attributedCents = 0;
                foreach (($activity['vehicle_allocations'] ?? []) as $allocation) {
                    $allocationCents = $this->decimalToCents((string) $allocation['amount']);
                    $vehicleId = (int) ($allocation['fleet_vehicle_id'] ?? 0);
                    if (! isset($vehicles[$vehicleId])) {
                        continue;
                    }
                    $vehicles[$vehicleId]['recorded_operating_costs_cents'] += $allocationCents;
                    $vehicles[$vehicleId]['activities'][] = $allocation;
                    $attributedCents += $allocationCents;
                }
                $residualCents = $amountCents - $attributedCents;
                if ($residualCents > 0) {
                    $unallocatedCostCents += $residualCents;
                    $unallocatedActivities[] = array_merge($activity, ['amount' => $this->centsToDecimal($residualCents)]);
                }
                continue;
            }

            $vehicleId = (int) ($activity['fleet_vehicle_id'] ?? 0);
            if (! isset($vehicles[$vehicleId])) {
                if ($group === 'recorded_operating_costs') {
                    $unallocatedCostCents += $amountCents;
                    $unallocatedActivities[] = $activity;
                } elseif ($group === 'realized_operating_revenue') {
                    $unallocatedRevenueCents += $amountCents;
                } elseif ($group === 'realized_recoveries') {
                    $unallocatedRecoveryCents += $amountCents;
                }
                continue;
            }

            $metric = match ($group) {
                'realized_operating_revenue' => 'realized_operating_revenue_cents',
                'realized_recoveries' => 'realized_recoveries_cents',
                'recorded_operating_costs' => 'recorded_operating_costs_cents',
                default => null,
            };
            if ($metric !== null) {
                $vehicles[$vehicleId][$metric] += $amountCents;
                $vehicles[$vehicleId]['activities'][] = $activity;
            }
        }

        $rows = [];
        foreach ($vehicles as $vehicle) {
            $netCents = $vehicle['realized_operating_revenue_cents'] + $vehicle['realized_recoveries_cents'] - $vehicle['recorded_operating_costs_cents'];
            $rows[] = array_merge($vehicle, [
                'realized_operating_revenue' => $this->centsToFloat($vehicle['realized_operating_revenue_cents']),
                'realized_recoveries' => $this->centsToFloat($vehicle['realized_recoveries_cents']),
                'recorded_operating_costs' => $this->centsToFloat($vehicle['recorded_operating_costs_cents']),
                'net_realized_operating_result' => $this->centsToFloat($netCents),
                'net_realized_operating_result_cents' => $netCents,
            ]);
        }
        $this->sortRows($rows, $sort, $direction);

        $vehicleRevenueCents = array_sum(array_column($rows, 'realized_operating_revenue_cents'));
        $vehicleRecoveryCents = array_sum(array_column($rows, 'realized_recoveries_cents'));
        $vehicleCostCents = array_sum(array_column($rows, 'recorded_operating_costs_cents'));
        $vehicleNetCents = array_sum(array_column($rows, 'net_realized_operating_result_cents'));
        $fleetRevenueCents = $this->decimalToCents((string) $fleet['realized_operating_revenue']);
        $fleetRecoveryCents = $this->decimalToCents((string) $fleet['realized_recoveries']);
        $fleetCostCents = $this->decimalToCents((string) $fleet['recorded_operating_costs']);
        $fleetNetCents = $this->decimalToCents((string) $fleet['net_realized_operating_result']);
        $reconciledNetCents = $vehicleNetCents + $unallocatedRevenueCents + $unallocatedRecoveryCents - $unallocatedCostCents;

        return [
            'company_id' => $companyId,
            'period_from' => $fromDate,
            'period_to_exclusive' => $toDateExclusive,
            'sort' => $sort,
            'direction' => $direction,
            'vehicles' => $rows,
            'fleet_summary' => $fleet,
            'fleet_wide_unallocated_costs' => $this->centsToFloat($unallocatedCostCents),
            'unallocated_realized_operating_revenue' => $this->centsToFloat($unallocatedRevenueCents),
            'unallocated_realized_recoveries' => $this->centsToFloat($unallocatedRecoveryCents),
            'unallocated_activities' => $unallocatedActivities,
            'reconciliation' => [
                'vehicle_costs' => $this->centsToFloat($vehicleCostCents),
                'fleet_wide_unallocated_costs' => $this->centsToFloat($unallocatedCostCents),
                'fleet_costs' => $this->centsToFloat($fleetCostCents),
                'cost_difference' => $this->centsToFloat($fleetCostCents - $vehicleCostCents - $unallocatedCostCents),
                'vehicle_revenue' => $this->centsToFloat($vehicleRevenueCents),
                'unallocated_revenue' => $this->centsToFloat($unallocatedRevenueCents),
                'fleet_revenue' => $this->centsToFloat($fleetRevenueCents),
                'revenue_difference' => $this->centsToFloat($fleetRevenueCents - $vehicleRevenueCents - $unallocatedRevenueCents),
                'vehicle_recoveries' => $this->centsToFloat($vehicleRecoveryCents),
                'unallocated_recoveries' => $this->centsToFloat($unallocatedRecoveryCents),
                'fleet_recoveries' => $this->centsToFloat($fleetRecoveryCents),
                'recovery_difference' => $this->centsToFloat($fleetRecoveryCents - $vehicleRecoveryCents - $unallocatedRecoveryCents),
                'reconciled_net_result' => $this->centsToFloat($reconciledNetCents),
                'fleet_net_result' => $this->centsToFloat($fleetNetCents),
                'net_difference' => $this->centsToFloat($fleetNetCents - $reconciledNetCents),
            ],
            'diagnostics' => array_merge($fleet['diagnostics'], [
                'vehicle_roster_query_count' => 1,
                'total_query_count' => (int) $fleet['diagnostics']['source_query_count'] + 1,
            ]),
        ];
    }

    /** @param list<array<string, mixed>> $rows */
    private function sortRows(array &$rows, string $sort, string $direction): void
    {
        usort($rows, function (array $left, array $right) use ($sort, $direction): int {
            $comparison = match ($sort) {
                'vehicle' => $this->vehicleOrder($left, $right),
                'revenue' => $left['realized_operating_revenue_cents'] <=> $right['realized_operating_revenue_cents'],
                'recoveries' => $left['realized_recoveries_cents'] <=> $right['realized_recoveries_cents'],
                'costs' => $left['recorded_operating_costs_cents'] <=> $right['recorded_operating_costs_cents'],
                default => $left['net_realized_operating_result_cents'] <=> $right['net_realized_operating_result_cents'],
            };
            if ($comparison === 0) {
                return $this->vehicleOrder($left, $right);
            }

            return $direction === 'desc' ? -$comparison : $comparison;
        });
    }

    /** @param array<string,mixed> $left @param array<string,mixed> $right */
    private function vehicleOrder(array $left, array $right): int
    {
        $number = ($left['fleet_number'] ?? PHP_INT_MAX) <=> ($right['fleet_number'] ?? PHP_INT_MAX);
        if ($number !== 0) {
            return $number;
        }
        $code = strnatcasecmp((string) $left['fleet_code'], (string) $right['fleet_code']);

        return $code !== 0 ? $code : $left['fleet_vehicle_id'] <=> $right['fleet_vehicle_id'];
    }

    /** @param array<string,mixed> $vehicle */
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

    private function decimalToCents(string $amount): int
    {
        $amount = trim($amount);
        if (preg_match('/^(-?)(\d+)(?:\.(\d{1,2}))?$/', $amount, $matches) !== 1) {
            throw new \UnexpectedValueException('Financial activity contains an invalid decimal amount.');
        }
        $cents = ((int) $matches[2] * 100) + (int) str_pad($matches[3] ?? '', 2, '0');

        return $matches[1] === '-' ? -$cents : $cents;
    }

    private function centsToDecimal(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $absolute = abs($cents);

        return sprintf('%s%d.%02d', $sign, intdiv($absolute, 100), $absolute % 100);
    }

    private function centsToFloat(int $cents): float
    {
        return round($cents / 100, 2);
    }

    private function financialSummary(): FinancialSummaryService
    {
        return $this->financialSummaryService ?? Services::financialSummaryService();
    }

    private function vehicles(): FleetVehicleRepository
    {
        return $this->vehicleRepository ?? Services::fleetVehicleRepository();
    }
}
