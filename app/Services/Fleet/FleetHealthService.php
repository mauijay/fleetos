<?php

namespace App\Services\Fleet;

use App\Repositories\FleetIntelligenceRepository;
use DateTimeImmutable;

class FleetHealthService
{
    public function __construct(
        private readonly ?FleetIntelligenceRepository $repository = null,
        private readonly ?OperationalMovementWorkService $movementWorkService = null,
        private readonly ?VehicleHealthReminderProjectionService $vehicleHealthProjectionService = null,
    ) {
    }

    /** Returns all operational alert categories for the fleet. */
    public function summary(?DateTimeImmutable $asOf = null): array
    {
        $asOf ??= new DateTimeImmutable();
        $vehicleHealthReminders = [];
        if (($this->vehicleHealthProjectionService ?? null) !== null) {
            $work = $this->movementWorkService ?? new OperationalMovementWorkService();
            $vehicleHealthReminders = $this->vehicleHealthReminders($work->singleActiveCompanyId($asOf), $asOf);
        }

        return [
            'vehicles_needing_cleaning' => $this->vehiclesNeedingCleaning($asOf),
            'vehicles_due_for_maintenance' => $this->vehiclesDueForMaintenance($asOf),
            'registration_expiring' => $this->registrationExpiring($asOf),
            'insurance_expiring' => $this->insuranceExpiring($asOf),
            'loan_payment_due' => $this->loanPaymentDue($asOf),
            'claims_requiring_follow_up' => $this->claimsRequiringFollowUp(),
            'vehicles_below_battery_threshold' => $this->vehiclesBelowBatteryThreshold(),
            'missing_photos' => $this->missingPhotos(),
            'missing_documents' => $this->missingDocuments(),
            'missing_turo_listing_data' => $this->missingTuroListingData(),
            'incomplete_vehicle_setup' => $this->incompleteVehicleSetup(),
            'vehicle_health_reminders' => $vehicleHealthReminders,
        ];
    }

    /** @return list<array<string, mixed>> */
    public function vehicleHealthReminders(int $companyId, ?DateTimeImmutable $asOf = null, bool $includeInactiveStates = false): array
    {
        // Some focused tests intentionally construct partial FleetHealthService doubles
        // without running the constructor. Keep this additive reader optional there.
        if (! isset($this->vehicleHealthProjectionService)) {
            return [];
        }

        return $this->vehicleHealthProjectionService->forCompany($companyId, $asOf, $includeInactiveStates);
    }

    /** Returns operator-held vehicles without a later Clean observation after return. */
    public function vehiclesNeedingCleaning(?DateTimeImmutable $asOf = null): array
    {
        $asOf ??= new DateTimeImmutable();
        $work = $this->movementWorkService ?? new OperationalMovementWorkService();

        return $work->cleaningNeedsForCompany($work->singleActiveCompanyId($asOf), $asOf);
    }

    /** Returns scheduled maintenance due within the alert horizon. */
    public function vehiclesDueForMaintenance(?DateTimeImmutable $asOf = null, int $horizonDays = 14): array
    {
        $asOf ??= new DateTimeImmutable();

        return $this->repo()->maintenanceDue($asOf->modify('+' . $horizonDays . ' days')->format('Y-m-d'));
    }

    /** Returns registrations expiring within the alert horizon. */
    public function registrationExpiring(?DateTimeImmutable $asOf = null, int $horizonDays = 45): array
    {
        $asOf ??= new DateTimeImmutable();

        return $this->repo()->expiringRegistrations($asOf->modify('+' . $horizonDays . ' days')->format('Y-m-d'));
    }

    /** Returns insurance policies expiring within the alert horizon. */
    public function insuranceExpiring(?DateTimeImmutable $asOf = null, int $horizonDays = 45): array
    {
        $asOf ??= new DateTimeImmutable();

        return $this->repo()->expiringInsurance($asOf->modify('+' . $horizonDays . ' days')->format('Y-m-d'));
    }

    /** Returns active loans with monthly payments due this month. */
    public function loanPaymentDue(?DateTimeImmutable $asOf = null): array
    {
        $asOf ??= new DateTimeImmutable();

        $loans = array_map(function (array $loan) use ($asOf): array {
            $dueOn = $this->loanDueDateForMonth($loan, $asOf);

            return array_merge($loan, [
                'due_month' => $asOf->format('Y-m'),
                'due_on' => $dueOn?->format('Y-m-d'),
                'due_label' => $dueOn === null
                    ? 'Due date unavailable'
                    : ($dueOn->format('Y-m-d') === $asOf->format('Y-m-d') ? 'Due today' : 'Due ' . $dueOn->format('M j')),
                'amount_due' => (float) ($loan['monthly_payment'] ?? 0),
            ]);
        }, $this->repo()->activeLoans());

        usort($loans, static function (array $left, array $right): int {
            $dateOrder = ($left['due_on'] ?? '9999-12-31') <=> ($right['due_on'] ?? '9999-12-31');
            if ($dateOrder !== 0) {
                return $dateOrder;
            }

            $leftFleetNumber = isset($left['fleet_number']) ? (int) $left['fleet_number'] : PHP_INT_MAX;
            $rightFleetNumber = isset($right['fleet_number']) ? (int) $right['fleet_number'] : PHP_INT_MAX;
            $fleetNumberOrder = $leftFleetNumber <=> $rightFleetNumber;

            return $fleetNumberOrder !== 0
                ? $fleetNumberOrder
                : strnatcasecmp((string) ($left['display_name'] ?? $left['fleet_code'] ?? ''), (string) ($right['display_name'] ?? $right['fleet_code'] ?? ''));
        });

        return $loans;
    }

    /** Returns open or unpaid claims that need operational follow-up. */
    public function claimsRequiringFollowUp(): array
    {
        return $this->repo()->openClaims();
    }

    /** Returns vehicles below the battery alert threshold when telemetry exists. */
    public function vehiclesBelowBatteryThreshold(int $thresholdPercent = 30): array
    {
        return [];
    }

    /** Returns vehicles missing required profile or inspection photos. */
    public function missingPhotos(): array
    {
        return $this->repo()->vehiclesMissingPhotos();
    }

    /** Returns vehicles missing required documents. */
    public function missingDocuments(): array
    {
        return $this->repo()->vehiclesMissingDocuments();
    }

    /** Returns vehicles without active source listing records. */
    public function missingTuroListingData(): array
    {
        return $this->repo()->vehiclesMissingTuroListings();
    }

    /** Returns vehicles missing any setup asset required for operational readiness. */
    public function incompleteVehicleSetup(): array
    {
        $vehicles = [];

        foreach (['photos' => $this->missingPhotos(), 'documents' => $this->missingDocuments(), 'listings' => $this->missingTuroListingData()] as $reason => $rows) {
            foreach ($rows as $row) {
                $id = (int) $row['id'];
                $vehicles[$id] ??= array_merge($row, ['missing' => []]);
                $vehicles[$id]['missing'][] = $reason;
            }
        }

        return array_values($vehicles);
    }

    private function repo(): FleetIntelligenceRepository
    {
        return $this->repository ?? service('fleetIntelligenceRepository');
    }

    private function loanDueDateForMonth(array $loan, DateTimeImmutable $asOf): ?DateTimeImmutable
    {
        $firstPaymentOn = trim((string) ($loan['first_payment_on'] ?? ''));
        if ($firstPaymentOn !== '') {
            $firstPaymentDate = DateTimeImmutable::createFromFormat('!Y-m-d', $firstPaymentOn, $asOf->getTimezone());
            if ($firstPaymentDate !== false && $firstPaymentDate->format('Y-m-d') === $firstPaymentOn) {
                if ($firstPaymentDate->format('Y-m') === $asOf->format('Y-m')) {
                    return $firstPaymentDate;
                }

                if ($firstPaymentDate > $asOf->modify('last day of this month')->setTime(23, 59, 59)) {
                    return null;
                }
            }
        }

        $dueDay = filter_var($loan['payment_due_day'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 31],
        ]);
        if ($dueDay === false) {
            return null;
        }

        $year = (int) $asOf->format('Y');
        $month = (int) $asOf->format('n');
        if (! checkdate($month, $dueDay, $year)) {
            return null;
        }

        return $asOf->setDate($year, $month, $dueDay)->setTime(0, 0);
    }
}
