<?php

namespace App\Services\Turo;

use App\Repositories\TuroEvChargingUpdateRepository;
use RuntimeException;
use Throwable;

class TuroEvChargingUpdateService
{
    private const RESERVATION_ALIASES = ['reservation_id'];
    private const VEHICLE_ALIASES = ['vehicle_id', 'turo_vehicle_id'];
    private const VIN_ALIASES = ['vin'];
    private const ON_TRIP_ALIASES = ['on_trip_ev_charging', 'on_trip_ev_charging_amount'];
    private const POST_TRIP_ALIASES = ['post_trip_ev_charging', 'post_trip_ev_charging_amount'];

    public function __construct(
        private readonly TuroEvChargingUpdateRepository $repository = new TuroEvChargingUpdateRepository(),
        private readonly TuroCsvReader $reader = new TuroCsvReader(),
        private readonly TuroImportAuditService $audit = new TuroImportAuditService(),
    ) {
    }

    /** @return array<string, mixed> */
    public function execute(string $filePath, int $companyId, ?int $actorUserId, bool $dryRun = true): array
    {
        if ($companyId < 1) {
            throw new RuntimeException('A valid company id is required.');
        }
        if (! $dryRun && ($actorUserId === null || $actorUserId < 1)) {
            throw new RuntimeException('Write mode requires a positive operator user id.');
        }

        $sourceHash = hash_file('sha256', $filePath);
        if (! is_string($sourceHash)) {
            throw new RuntimeException('Unable to fingerprint the Turo CSV.');
        }

        $report = [
            'mode' => $dryRun ? 'dry_run' : 'write',
            'status' => 'planned',
            'source_filename' => basename($filePath),
            'source_sha256' => $sourceHash,
            'company_id' => $companyId,
            'actor_user_id' => $actorUserId,
            'executed_at' => date(DATE_ATOM),
            'reservation_count' => 0,
            'matched_count' => 0,
            'proposed_on_trip_changes' => 0,
            'proposed_post_trip_changes' => 0,
            'no_change_count' => 0,
            'conflict_count' => 0,
            'error_count' => 0,
            'applied_count' => 0,
            'rows' => [],
        ];

        $sourceRows = iterator_to_array($this->reader->read($filePath), false);
        $report['reservation_count'] = count($sourceRows);
        $reservationCounts = [];
        foreach ($sourceRows as $sourceRow) {
            $reservation = $this->value($sourceRow->row, self::RESERVATION_ALIASES);
            if ($reservation !== null) {
                $reservationCounts[$reservation] = ($reservationCounts[$reservation] ?? 0) + 1;
            }
        }

        $plans = [];
        foreach ($sourceRows as $sourceRow) {
            $row = $this->planRow($sourceRow->rowNumber, $sourceRow->row, $companyId, $reservationCounts);
            $report['rows'][] = $row;
            if ($row['status'] === 'error') {
                $report['error_count']++;
                continue;
            }
            $report['matched_count']++;
            if ($row['status'] === 'conflict') {
                $report['conflict_count']++;
                continue;
            }
            if ($row['status'] === 'no_change') {
                $report['no_change_count']++;
                continue;
            }
            $report['proposed_on_trip_changes'] += $row['on_trip_changed'] ? 1 : 0;
            $report['proposed_post_trip_changes'] += $row['post_trip_changed'] ? 1 : 0;
            $plans[] = $row;
        }

        if ($dryRun) {
            $report['status'] = ($report['error_count'] + $report['conflict_count']) > 0 ? 'blocked' : 'dry_run_complete';

            return $report;
        }
        if (($report['error_count'] + $report['conflict_count']) > 0) {
            $report['status'] = 'blocked';

            return $report;
        }

        $this->repository->begin();
        try {
            foreach ($plans as $plan) {
                $updated = $this->repository->updateOptimistically(
                    $plan['trip_id'],
                    $plan['fleet_vehicle_id'],
                    $plan['old_on_trip_raw'],
                    $plan['old_post_trip_raw'],
                    $plan['new_on_trip'],
                    $plan['new_post_trip'],
                );
                if (! $updated) {
                    throw new RuntimeException('Optimistic write guard failed for reservation ' . $plan['reservation_id'] . '.');
                }
                $this->audit->updated(
                    $actorUserId,
                    'turo_trips_normalized',
                    $plan['trip_id'],
                    [
                        'on_trip_ev_charging_amount' => $plan['old_on_trip'],
                        'post_trip_ev_charging_amount' => $plan['old_post_trip'],
                    ],
                    [
                        'on_trip_ev_charging_amount' => $plan['new_on_trip'],
                        'post_trip_ev_charging_amount' => $plan['new_post_trip'],
                        'source_filename' => $report['source_filename'],
                        'source_sha256' => $report['source_sha256'],
                    ],
                );
                $report['applied_count']++;
            }
            if (! $this->repository->commit()) {
                throw new RuntimeException('Unable to commit the guarded EV charging update.');
            }
        } catch (Throwable $exception) {
            $this->repository->rollback();
            throw $exception;
        }

        $report['status'] = 'applied';

        return $report;
    }

    /** @param array<string, string|null> $payload @param array<string, int> $reservationCounts @return array<string, mixed> */
    private function planRow(int $rowNumber, array $payload, int $companyId, array $reservationCounts): array
    {
        $reservation = $this->value($payload, self::RESERVATION_ALIASES);
        if ($reservation === null) {
            return $this->errorRow($rowNumber, null, 'missing_reservation', 'Reservation ID is required.');
        }
        if (($reservationCounts[$reservation] ?? 0) > 1) {
            return $this->errorRow($rowNumber, $reservation, 'duplicate_source_reservation', 'Reservation appears more than once in the source CSV.');
        }

        $onTripCents = $this->moneyCents($this->value($payload, self::ON_TRIP_ALIASES));
        $postTripCents = $this->moneyCents($this->value($payload, self::POST_TRIP_ALIASES));
        if ($onTripCents === null || $postTripCents === null) {
            return $this->errorRow($rowNumber, $reservation, 'invalid_money', 'EV charging fields must be non-negative amounts with at most two decimal places.');
        }

        $trips = $this->repository->tripsByReservation($reservation);
        if ($trips === []) {
            return $this->errorRow($rowNumber, $reservation, 'trip_not_found', 'No normalized trip exists for this reservation.');
        }
        if (count($trips) !== 1) {
            return $this->errorRow($rowNumber, $reservation, 'trip_ambiguous', 'Reservation resolves to more than one normalized trip.');
        }
        $trip = $trips[0];
        if ((int) ($trip['company_id'] ?? 0) !== $companyId) {
            return $this->errorRow($rowNumber, $reservation, 'company_mismatch', 'Normalized trip does not belong to the requested company.');
        }

        $sourceVehicleId = $this->value($payload, self::VEHICLE_ALIASES);
        $fleetVehicleId = (int) ($trip['fleet_vehicle_id'] ?? 0);
        if ($sourceVehicleId === null || $fleetVehicleId < 1 || ! in_array($sourceVehicleId, $this->repository->activeTuroVehicleIds($fleetVehicleId), true)) {
            return $this->errorRow($rowNumber, $reservation, 'vehicle_mismatch', 'Source Turo vehicle does not exactly match the trip vehicle mapping.');
        }

        $sourceVin = $this->value($payload, self::VIN_ALIASES);
        $fleetVin = trim((string) ($trip['vin'] ?? ''));
        if ($sourceVin !== null && ($fleetVin === '' || strcasecmp($sourceVin, $fleetVin) !== 0)) {
            return $this->errorRow($rowNumber, $reservation, 'vin_mismatch', 'Source VIN does not exactly match the FleetOS vehicle VIN.');
        }

        $oldOnTripRaw = $trip['on_trip_ev_charging_amount'] === null ? null : (string) $trip['on_trip_ev_charging_amount'];
        $oldPostTripRaw = $trip['post_trip_ev_charging_amount'] === null ? null : (string) $trip['post_trip_ev_charging_amount'];
        $oldOnTripCents = $this->storedMoneyCents($oldOnTripRaw);
        $oldPostTripCents = $this->storedMoneyCents($oldPostTripRaw);
        $conflicts = [];
        if ($oldOnTripCents > 0 && $onTripCents > 0 && $oldOnTripCents !== $onTripCents) {
            $conflicts[] = 'on_trip_ev_charging_amount';
        }
        if ($oldPostTripCents > 0 && $postTripCents > 0 && $oldPostTripCents !== $postTripCents) {
            $conflicts[] = 'post_trip_ev_charging_amount';
        }

        $newOnTripCents = $onTripCents === 0 && $oldOnTripCents > 0 ? $oldOnTripCents : $onTripCents;
        $newPostTripCents = $postTripCents === 0 && $oldPostTripCents > 0 ? $oldPostTripCents : $postTripCents;
        $base = [
            'row_number' => $rowNumber,
            'reservation_id' => $reservation,
            'trip_id' => (int) $trip['id'],
            'fleet_vehicle_id' => $fleetVehicleId,
            'vehicle' => (string) $trip['fleet_code'],
            'old_on_trip' => $this->decimalFromCents($oldOnTripCents),
            'new_on_trip' => $this->decimalFromCents($newOnTripCents),
            'old_post_trip' => $this->decimalFromCents($oldPostTripCents),
            'new_post_trip' => $this->decimalFromCents($newPostTripCents),
            'old_on_trip_raw' => $oldOnTripRaw,
            'old_post_trip_raw' => $oldPostTripRaw,
            'on_trip_changed' => $oldOnTripCents !== $newOnTripCents,
            'post_trip_changed' => $oldPostTripCents !== $newPostTripCents,
        ];
        if ($conflicts !== []) {
            return [...$base, 'status' => 'conflict', 'code' => 'conflicting_nonzero_value', 'detail' => implode(', ', $conflicts)];
        }
        if (! $base['on_trip_changed'] && ! $base['post_trip_changed']) {
            return [...$base, 'status' => 'no_change', 'code' => 'values_preserved', 'detail' => null];
        }

        return [...$base, 'status' => 'proposed', 'code' => null, 'detail' => null];
    }

    /** @return array<string, mixed> */
    private function errorRow(int $rowNumber, ?string $reservation, string $code, string $detail): array
    {
        return [
            'row_number' => $rowNumber,
            'reservation_id' => $reservation,
            'status' => 'error',
            'code' => $code,
            'detail' => $detail,
        ];
    }

    /** @param array<string, string|null> $payload @param list<string> $aliases */
    private function value(array $payload, array $aliases): ?string
    {
        foreach ($aliases as $alias) {
            $value = trim((string) ($payload[$alias] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function moneyCents(?string $value): ?int
    {
        $text = trim((string) $value);
        $text = str_replace([',', '$'], '', $text);
        if (! preg_match('/^\d+(?:\.\d{1,2})?$/', $text)) {
            return null;
        }
        [$whole, $fraction] = array_pad(explode('.', $text, 2), 2, '');

        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }

    private function storedMoneyCents(?string $value): int
    {
        if ($value === null) {
            return 0;
        }
        $cents = $this->moneyCents($value);
        if ($cents === null) {
            throw new RuntimeException('Stored EV charging amount is not a valid DECIMAL value.');
        }

        return $cents;
    }

    private function decimalFromCents(int $cents): string
    {
        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }
}
