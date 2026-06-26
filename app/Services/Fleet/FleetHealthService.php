<?php

namespace App\Services\Fleet;

use App\Repositories\FleetIntelligenceRepository;
use DateTimeImmutable;

class FleetHealthService
{
    public function __construct(private readonly ?FleetIntelligenceRepository $repository = null)
    {
    }

    /** Returns all operational alert categories for the fleet. */
    public function summary(?DateTimeImmutable $asOf = null): array
    {
        $asOf ??= new DateTimeImmutable();

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
        ];
    }

    /** Returns vehicles with completed returns today that need cleaning workflow review. */
    public function vehiclesNeedingCleaning(?DateTimeImmutable $asOf = null): array
    {
        $asOf ??= new DateTimeImmutable();
        $start = $asOf->setTime(0, 0)->format('Y-m-d H:i:s');
        $end = $asOf->setTime(23, 59, 59)->format('Y-m-d H:i:s');

        return array_values(array_filter($this->repo()->reservationsBetween($start, $end), static function (array $reservation) use ($asOf): bool {
            return ($reservation['ends_at'] ?? '') <= $asOf->format('Y-m-d H:i:s')
                && ! in_array($reservation['status_code'] ?? '', ['canceled_zero_payout', 'canceled_host_payout'], true);
        }));
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

        return array_map(static function (array $loan) use ($asOf): array {
            return array_merge($loan, [
                'due_month' => $asOf->format('Y-m'),
                'amount_due' => (float) ($loan['monthly_payment'] ?? 0),
            ]);
        }, $this->repo()->activeLoans());
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
}
