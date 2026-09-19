<?php

namespace App\Services\Fleet;

use App\Exceptions\EarlyHandoffConfirmationRequired;
use App\Repositories\AirportMovementRepository;
use App\Repositories\VehicleRecoveryExceptionRepository;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Config\MovementIntelligence;
use RuntimeException;

class MovementOperationalFactService
{
    private BaseConnection $db;

    public function __construct(
        ?BaseConnection $db = null,
        private readonly MovementEventService $events = new MovementEventService(),
        private readonly MovementAssessmentService $assessments = new MovementAssessmentService(),
        private readonly ?VehiclePositioningPlanService $positioningPlans = null,
        private readonly MovementIntelligence $movementConfig = new MovementIntelligence(),
        private readonly ?VehicleRecoveryExceptionRepository $recoveryExceptions = null,
    ) {
        $this->db = $db ?? Database::connect();
    }

    public function recordForChecklist(array $checklist, array $data, int $actorUserId): bool
    {
        if (! ($checklist['exists'] ?? false)) {
            return false;
        }
        $movementType = (string) $checklist['movement_type'];
        $eventCode = $movementType === 'pickup' ? 'actual_handoff' : 'actual_return';
        if ($eventCode === 'actual_handoff' && ($data['location_class'] ?? null) === 'airport_hnl') {
            throw new \InvalidArgumentException('Stage the vehicle at HNL, then confirm guest pickup separately.');
        }
        if ($eventCode === 'actual_handoff') {
            $this->rejectDuplicateHandoff($checklist);
            $this->requireEarlyHandoffConfirmation($checklist, $data);
        } elseif ($this->events->activeForTrip((int) $checklist['turo_trip_normalized_id'], ['actual_return', 'vehicle_recovered']) !== null) {
            throw new \InvalidArgumentException('This return is already complete. Correct or void the existing fact instead.');
        }

        return $this->recordObservation($checklist, $data, $actorUserId, $eventCode);
    }

    public function stageGuestReturn(array $checklist, array $data, int $actorUserId): int
    {
        if (! ($checklist['exists'] ?? false) || ($checklist['movement_type'] ?? null) !== 'return') {
            throw new \InvalidArgumentException('Choose a return movement in the active company.');
        }
        $this->requireCompanyVehicle((int) ($checklist['company_id'] ?? 0), (int) ($checklist['fleet_vehicle_id'] ?? 0), $actorUserId);
        $tripId = (int) $checklist['turo_trip_normalized_id'];
        if ($this->events->activeForTrip($tripId, ['actual_return', 'vehicle_recovered']) !== null) {
            throw new \InvalidArgumentException('The vehicle return is already complete.');
        }
        if ($this->events->activeForTrip($tripId, ['guest_return_staged']) !== null) {
            throw new \InvalidArgumentException('A guest return report is already active. Correct or void it instead.');
        }

        $reportedTime = trim((string) ($data['reported_parked_at'] ?? ''));
        try {
            $occurredAt = $reportedTime === '' ? new \DateTimeImmutable() : new \DateTimeImmutable($reportedTime);
        } catch (\Exception) {
            throw new \InvalidArgumentException('Choose a valid guest-reported parked time.');
        }
        if ($occurredAt > new \DateTimeImmutable()) {
            throw new \InvalidArgumentException('Guest-reported parked time cannot be in the future.');
        }
        $garage = trim((string) ($data['airport_garage_code'] ?? ''));
        $level = $data['airport_parking_level'] ?? null;
        $row = trim((string) ($data['airport_parking_row'] ?? ''));
        $parking = $garage === '' && trim((string) $level) === '' && $row === ''
            ? []
            : (new HnlGarageCatalog())->validate($garage, $level, $row);
        $locationNote = trim((string) ($data['location_note'] ?? ''));
        $reportNote = trim((string) ($data['guest_report_note'] ?? ''));
        $note = implode("\n", array_filter([
            $locationNote === '' ? '' : 'Location note: ' . $locationNote,
            $reportNote === '' ? '' : 'Guest report: ' . $reportNote,
        ]));

        $this->db->transBegin();
        try {
            if ($this->events->activeForTrip($tripId, ['guest_return_staged', 'actual_return', 'vehicle_recovered']) !== null) {
                throw new \InvalidArgumentException('The return state changed; reload this movement.');
            }
            $eventId = $this->events->record(
                (int) $checklist['fleet_vehicle_id'],
                $tripId,
                'guest_return_staged',
                'return',
                $occurredAt->format('Y-m-d H:i:s'),
                'airport_hnl',
                null,
                $reportedTime === '' ? 'guest_report_received' : 'guest_reported_parked_time',
                $actorUserId,
                $note === '' ? null : $note,
                $parking,
            );
            if ($this->db->transStatus() === false) {
                throw new RuntimeException('Guest return report transaction failed.');
            }
            $this->db->transCommit();

            return $eventId;
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }

    public function recoverVehicle(array $checklist, array $data, int $actorUserId): int
    {
        if (! ($checklist['exists'] ?? false) || ($checklist['movement_type'] ?? null) !== 'return') {
            throw new \InvalidArgumentException('Choose a return movement in the active company.');
        }
        $companyId = (int) ($checklist['company_id'] ?? 0);
        $vehicleId = (int) ($checklist['fleet_vehicle_id'] ?? 0);
        $tripId = (int) ($checklist['turo_trip_normalized_id'] ?? 0);
        $this->requireCompanyVehicle($companyId, $vehicleId, $actorUserId);
        $trip = $this->repo()->trip($tripId);
        if ($trip === null || (int) $trip['company_id'] !== $companyId || (int) $trip['fleet_vehicle_id'] !== $vehicleId) {
            throw new \InvalidArgumentException('Trip not found in the active fleet company.');
        }
        if (($data['confirm_recovery_location'] ?? null) !== '1') {
            throw new \InvalidArgumentException('Confirm the actual recovery location before recording recovery.');
        }
        $occurredAt = $this->requiredPastTimestamp($data, 'Recovery time');
        $locationClass = trim((string) ($data['location_class'] ?? ''));
        if (! in_array($locationClass, ['airport_hnl', 'home', 'other_delivery'], true)) {
            throw new \InvalidArgumentException('Choose the actual recovery location.');
        }
        $parking = [];
        if ($locationClass === 'airport_hnl') {
            $parking = (new HnlGarageCatalog())->validate(
                (string) ($data['airport_garage_code'] ?? ''),
                $data['airport_parking_level'] ?? null,
                (string) ($data['airport_parking_row'] ?? ''),
            );
        }
        $unknown = ($data['energy_unknown'] ?? null) === '1';
        $energyValue = trim((string) ($data['energy_percent'] ?? ''));
        if ($unknown === ($energyValue !== '')) {
            throw new \InvalidArgumentException('Record a measured charge/fuel percentage or mark energy unknown.');
        }
        $energy = null;
        $reason = trim((string) ($data['energy_unknown_reason'] ?? ''));
        if ($unknown) {
            if ($reason === '') {
                throw new \InvalidArgumentException('Explain why charge/fuel could not be measured.');
            }
        } else {
            $energy = filter_var($energyValue, FILTER_VALIDATE_INT);
            if ($energy === false || $energy < 0 || $energy > 100) {
                throw new \InvalidArgumentException('Charge/fuel percentage must be between 0 and 100.');
            }
        }

        $exceptionCodes = $data['exception_codes'] ?? [];
        $exceptionNotes = $data['exception_notes'] ?? [];
        if (! is_array($exceptionCodes) || ! is_array($exceptionNotes)) {
            throw new \InvalidArgumentException('Choose valid recovery exceptions.');
        }
        $allowedExceptionCodes = ['damage', 'missing_key', 'missing_charge_adapter', 'not_drivable', 'other'];
        $exceptions = [];
        foreach ($exceptionCodes as $code) {
            if (! is_string($code) || ! in_array($code, $allowedExceptionCodes, true) || array_key_exists($code, $exceptions)) {
                throw new \InvalidArgumentException('Choose each supported recovery exception at most once.');
            }
            $note = $exceptionNotes[$code] ?? null;
            if ($note !== null && ! is_string($note)) {
                throw new \InvalidArgumentException('Recovery exception notes must be text.');
            }
            $note = trim((string) $note);
            if (mb_strlen($note) > 2000 || ($code === 'other' && $note === '')) {
                throw new \InvalidArgumentException('Describe the Other recovery exception in 2000 characters or fewer.');
            }
            $exceptions[$code] = $note === '' ? null : $note;
        }

        $this->db->transBegin();
        try {
            if ($this->events->activeForTrip($tripId, ['actual_return', 'vehicle_recovered']) !== null) {
                throw new \InvalidArgumentException('This return is already complete. Correct or void the existing fact instead.');
            }
            $custody = $this->repo()->latestActiveLifecycleEvent($vehicleId);
            if ((int) ($custody['turo_trip_normalized_id'] ?? 0) !== $tripId
                || ! in_array($custody['event_code'] ?? null, ['actual_handoff', 'guest_return_staged'], true)
                || (string) $custody['occurred_at'] > (new \DateTimeImmutable($occurredAt))->format('Y-m-d H:i:s')) {
                throw new \InvalidArgumentException('This trip is not awaiting operator recovery at the selected time.');
            }
            $locationNote = trim((string) ($data['recovery_location_note'] ?? ''));
            $note = trim(implode("\n", array_filter([
                $locationNote === '' ? '' : 'Recovery location detail: ' . $locationNote,
                trim((string) ($data['note'] ?? '')),
            ])));
            $assessmentNote = $unknown ? 'Energy unknown: ' . $reason : ($note ?: null);
            $eventId = $this->events->record(
                $vehicleId,
                $tripId,
                'vehicle_recovered',
                'return',
                $occurredAt,
                $locationClass,
                $locationClass === 'airport_hnl' ? null : ($data['location_detail'] ?? null),
                'checklist_operator',
                $actorUserId,
                $note ?: null,
                $parking,
            );
            $this->assessments->record($vehicleId, $tripId, $eventId, 'return', null, $energy, $occurredAt, 'checklist_operator', $actorUserId, $assessmentNote);
            foreach ($exceptions as $code => $exceptionNote) {
                ($this->recoveryExceptions ?? new VehicleRecoveryExceptionRepository($this->db))
                    ->createForRecovery($companyId, $tripId, $vehicleId, $eventId, $code, $exceptionNote, $actorUserId);
            }
            $this->plans()->invalidateForWrite($vehicleId, 'new_actual_movement_event', $actorUserId);
            if ($this->db->transStatus() === false) {
                throw new RuntimeException('Vehicle recovery transaction failed.');
            }
            $this->db->transCommit();

            return $eventId;
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }

    public function voidRecoveredVehicle(array $checklist, int $eventId, int $actorUserId, string $reason): bool
    {
        if (! ($checklist['exists'] ?? false) || ($checklist['movement_type'] ?? null) !== 'return') {
            throw new \InvalidArgumentException('Choose a return movement in the active company.');
        }
        $companyId = (int) ($checklist['company_id'] ?? 0);
        $vehicleId = (int) ($checklist['fleet_vehicle_id'] ?? 0);
        $tripId = (int) ($checklist['turo_trip_normalized_id'] ?? 0);
        $this->requireCompanyVehicle($companyId, $vehicleId, $actorUserId);
        $event = $this->events->find($eventId);
        $assessment = $this->repo()->assessmentForEventOrTrip($eventId, null);
        if ($event === null || $event['event_code'] !== 'vehicle_recovered' || $event['voided_at'] !== null
            || (int) $event['company_id'] !== $companyId || (int) $event['fleet_vehicle_id'] !== $vehicleId
            || (int) $event['turo_trip_normalized_id'] !== $tripId || $assessment === null
            || (int) $assessment['trip_movement_event_id'] !== $eventId) {
            throw new \InvalidArgumentException('Active vehicle recovery not found in the active company.');
        }
        if (trim($reason) === '') {
            throw new \InvalidArgumentException('A void reason is required.');
        }

        $this->db->transBegin();
        try {
            if (! $this->assessments->void((int) $assessment['id'], $actorUserId, $reason)
                || ! $this->events->void($eventId, $actorUserId, $reason)) {
                throw new RuntimeException('Vehicle recovery could not be voided.');
            }
            $this->plans()->invalidateForWrite($vehicleId, 'voided_actual_movement_event', $actorUserId);
            if ($this->db->transStatus() === false) {
                throw new RuntimeException('Vehicle recovery void transaction failed.');
            }
            $this->db->transCommit();

            return true;
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }

    public function stageForChecklist(array $checklist, array $data, int $actorUserId): bool
    {
        if (! ($checklist['exists'] ?? false)) {
            return false;
        }
        if (($checklist['movement_type'] ?? null) !== 'pickup' || ($data['location_class'] ?? null) !== 'airport_hnl') {
            throw new \InvalidArgumentException('Only an Airport HNL pickup movement can be staged.');
        }
        if (! in_array($data['cleanliness'] ?? null, ['clean', 'dirty'], true) || ($data['energy_percent'] ?? '') === '') {
            throw new \InvalidArgumentException('Capture cleanliness and charge or fuel before staging at HNL.');
        }
        $active = $this->events->latestForTrip((int) $checklist['turo_trip_normalized_id']);
        if (in_array($active['event_code'] ?? null, ['vehicle_staged', 'actual_handoff'], true)) {
            throw new \InvalidArgumentException('This pickup is already staged or confirmed.');
        }

        return $this->recordObservation($checklist, $data, $actorUserId, 'vehicle_staged');
    }

    public function recordVehiclePosition(array $checklist, array $data, int $actorUserId): bool
    {
        if (! ($checklist['exists'] ?? false)) {
            return false;
        }
        $occurredAt = trim((string) ($data['occurred_at'] ?? ''));
        $locationClass = trim((string) ($data['location_class'] ?? ''));
        if ($occurredAt === '' || $locationClass === '' || $locationClass === 'unknown') {
            throw new \InvalidArgumentException('Actual position time and location are required.');
        }
        try {
            $occurred = new \DateTimeImmutable($occurredAt);
        } catch (\Exception) {
            throw new \InvalidArgumentException('Choose a valid actual position time.');
        }
        if ($occurred > new \DateTimeImmutable()) {
            throw new \InvalidArgumentException('Actual position time cannot be in the future.');
        }
        $active = $this->events->latestForTrip((int) $checklist['turo_trip_normalized_id']);
        if (($active['event_code'] ?? null) === 'actual_handoff') {
            throw new \InvalidArgumentException('Record the actual return before recording a parked vehicle position.');
        }
        if (($checklist['movement_type'] ?? null) === 'pickup' && $locationClass === 'airport_hnl') {
            throw new \InvalidArgumentException('Use Stage at HNL for an Airport HNL pickup.');
        }
        $this->rejectExactPositionReplay($checklist, $data, $actorUserId);

        $this->db->transBegin();
        try {
            $this->rejectExactPositionReplay($checklist, $data, $actorUserId);
            $this->events->record(
                (int) $checklist['fleet_vehicle_id'],
                (int) $checklist['turo_trip_normalized_id'],
                'vehicle_positioned',
                null,
                $occurredAt,
                $locationClass,
                $data['location_detail'] ?? null,
                'checklist_operator',
                $actorUserId,
                $data['note'] ?? null,
                [
                    'garage_code' => $data['airport_garage_code'] ?? null,
                    'level' => $data['airport_parking_level'] ?? null,
                    'row' => $data['airport_parking_row'] ?? null,
                    'structured_input_present' => array_key_exists('airport_garage_code', $data),
                ],
            );
            $this->plans()->invalidateForWrite((int) $checklist['fleet_vehicle_id'], 'actual_vehicle_position_recorded', $actorUserId);
            if ($this->db->transStatus() === false) {
                throw new RuntimeException('Vehicle position transaction failed.');
            }
            $this->db->transCommit();

            return true;
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }

    public function recordCurrentPositionForVehicle(int $companyId, int $vehicleId, array $data, int $actorUserId): bool
    {
        $this->requireCompanyVehicle($companyId, $vehicleId, $actorUserId);
        $occurredAt = $this->requiredPastTimestamp($data, 'Actual position time');
        $locationClass = trim((string) ($data['location_class'] ?? ''));
        if (! in_array($locationClass, ['home', 'airport_hnl', 'other_delivery'], true)) {
            throw new \InvalidArgumentException('Choose Home, Airport HNL, or Other for the current position.');
        }
        $this->rejectGuestPossession($vehicleId);
        $this->rejectExactVehiclePositionReplay($vehicleId, $data, $actorUserId);

        $this->db->transBegin();
        try {
            $this->rejectGuestPossession($vehicleId);
            $this->rejectExactVehiclePositionReplay($vehicleId, $data, $actorUserId);
            $this->events->record(
                $vehicleId,
                null,
                'vehicle_positioned',
                null,
                $occurredAt,
                $locationClass,
                $data['location_detail'] ?? null,
                'vehicle_operator',
                $actorUserId,
                $data['note'] ?? null,
                $this->airportParkingData($data),
            );
            $this->plans()->invalidateForWrite($vehicleId, 'actual_vehicle_position_recorded', $actorUserId);
            if ($this->db->transStatus() === false) {
                throw new RuntimeException('Vehicle position transaction failed.');
            }
            $this->db->transCommit();

            return true;
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }

    public function recordCurrentReadinessForVehicle(int $companyId, int $vehicleId, array $data, int $actorUserId): bool
    {
        $this->requireCompanyVehicle($companyId, $vehicleId, $actorUserId);
        $observedAt = $this->requiredPastTimestamp($data, 'Readiness observation time');
        $cleanliness = trim((string) ($data['cleanliness'] ?? '')) ?: null;
        if (! in_array($cleanliness, ['clean', 'dirty', null], true)) {
            throw new \InvalidArgumentException('Choose Clean or Dirty, or leave cleanliness unobserved.');
        }
        $energyValue = trim((string) ($data['energy_percent'] ?? ''));
        $energy = $energyValue === '' ? null : filter_var($energyValue, FILTER_VALIDATE_INT);
        if ($energy === false || ($energy !== null && ($energy < 0 || $energy > 100))) {
            throw new \InvalidArgumentException('Energy must be between 0 and 100.');
        }
        if ($cleanliness === null && $energy === null) {
            throw new \InvalidArgumentException('Observe cleanliness or charge/fuel percentage before saving.');
        }
        $this->rejectGuestPossession($vehicleId);
        $this->rejectExactReadinessReplay($companyId, $vehicleId, $cleanliness, $energy, $observedAt);

        $this->db->transBegin();
        try {
            $this->rejectGuestPossession($vehicleId);
            $this->rejectExactReadinessReplay($companyId, $vehicleId, $cleanliness, $energy, $observedAt);
            $eventId = $this->events->record(
                $vehicleId,
                null,
                'vehicle_readiness_observed',
                null,
                $observedAt,
                null,
                null,
                'vehicle_operator',
                $actorUserId,
                $data['note'] ?? null,
            );
            $this->assessments->record(
                $vehicleId,
                null,
                $eventId,
                'current',
                $cleanliness,
                $energy,
                $observedAt,
                'vehicle_operator',
                $actorUserId,
                $data['note'] ?? null,
            );
            if ($this->db->transStatus() === false) {
                throw new RuntimeException('Current readiness transaction failed.');
            }
            $this->db->transCommit();

            return true;
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }

    private function rejectExactPositionReplay(array $checklist, array $data, int $actorUserId): void
    {
        if ($this->events->hasExactActivePosition(
            (int) $checklist['fleet_vehicle_id'],
            (int) $checklist['turo_trip_normalized_id'],
            (string) $data['occurred_at'],
            (string) $data['location_class'],
            $data['location_detail'] ?? null,
            $actorUserId,
            [
                'garage_code' => $data['airport_garage_code'] ?? null,
                'level' => $data['airport_parking_level'] ?? null,
                'row' => $data['airport_parking_row'] ?? null,
                'structured_input_present' => array_key_exists('airport_garage_code', $data),
            ],
        )) {
            throw new \InvalidArgumentException('This exact vehicle position is already recorded.');
        }
    }

    private function rejectExactVehiclePositionReplay(int $vehicleId, array $data, int $actorUserId): void
    {
        if ($this->events->hasExactActivePosition(
            $vehicleId,
            null,
            (string) $data['occurred_at'],
            (string) $data['location_class'],
            $data['location_detail'] ?? null,
            $actorUserId,
            $this->airportParkingData($data),
            'vehicle_operator',
        )) {
            throw new \InvalidArgumentException('This exact vehicle position is already recorded.');
        }
    }

    private function rejectExactReadinessReplay(int $companyId, int $vehicleId, ?string $cleanliness, ?int $energy, string $observedAt): void
    {
        if ($this->assessments->hasExactActiveCurrent($companyId, $vehicleId, $cleanliness, $energy, $observedAt)) {
            throw new \InvalidArgumentException('This exact current readiness observation is already recorded.');
        }
    }

    /** @return array<string, mixed> */
    private function airportParkingData(array $data): array
    {
        return [
            'garage_code' => $data['airport_garage_code'] ?? null,
            'level' => $data['airport_parking_level'] ?? null,
            'row' => $data['airport_parking_row'] ?? null,
            'structured_input_present' => array_key_exists('airport_garage_code', $data),
        ];
    }

    private function requireCompanyVehicle(int $companyId, int $vehicleId, int $actorUserId): void
    {
        if ($actorUserId < 1 || $this->repo()->vehicleForCompany($companyId, $vehicleId) === null) {
            throw new \InvalidArgumentException('Vehicle not found in the active fleet company.');
        }
    }

    private function rejectGuestPossession(int $vehicleId): void
    {
        $lifecycle = $this->repo()->latestActiveLifecycleEvent($vehicleId);
        if (in_array($lifecycle['event_code'] ?? null, ['actual_handoff', 'guest_return_staged'], true)) {
            throw new \InvalidArgumentException('Record the actual return or recovery before updating current vehicle state.');
        }
    }

    private function requiredPastTimestamp(array $data, string $label): string
    {
        $value = trim((string) ($data['occurred_at'] ?? ''));
        if ($value === '') {
            throw new \InvalidArgumentException($label . ' is required.');
        }
        try {
            $timestamp = new \DateTimeImmutable($value);
        } catch (\Exception) {
            throw new \InvalidArgumentException('Choose a valid ' . strtolower($label) . '.');
        }
        if ($timestamp > new \DateTimeImmutable()) {
            throw new \InvalidArgumentException($label . ' cannot be in the future.');
        }

        return $value;
    }

    public function confirmGuestPickup(array $checklist, array $data, int $actorUserId): bool
    {
        if (! ($checklist['exists'] ?? false)) {
            return false;
        }
        if (($checklist['movement_type'] ?? null) !== 'pickup') {
            throw new \InvalidArgumentException('Guest pickup can only be confirmed for a pickup movement.');
        }
        $this->rejectDuplicateHandoff($checklist);
        $active = $this->events->latestForTrip((int) $checklist['turo_trip_normalized_id']);
        if (($active['event_code'] ?? null) !== 'vehicle_staged') {
            throw new \InvalidArgumentException('Stage the vehicle at HNL before confirming guest pickup.');
        }
        $occurredAt = trim((string) ($data['occurred_at'] ?? ''));
        if ($occurredAt === '') {
            throw new \InvalidArgumentException('Guest pickup time is required.');
        }
        $this->requireEarlyHandoffConfirmation($checklist, $data);

        $this->db->transBegin();
        try {
            $this->rejectDuplicateHandoff($checklist);
            $this->events->record(
                (int) $checklist['fleet_vehicle_id'],
                (int) $checklist['turo_trip_normalized_id'],
                'actual_handoff',
                'pickup',
                $occurredAt,
                $active['location_class'] ?? 'airport_hnl',
                $active['location_detail'] ?? null,
                'checklist_operator',
                $actorUserId,
                $data['note'] ?? null,
                [
                    'garage_code' => $active['airport_garage_code'] ?? null,
                    'level' => $active['airport_parking_level'] ?? null,
                    'row' => $active['airport_parking_row'] ?? null,
                ],
            );
            $this->syncAirportWorkflow($checklist, [
                'workflow_status' => 'picked_up',
                'guest_pickup_confirmed_at' => (new \DateTimeImmutable($occurredAt))->format('Y-m-d H:i:s'),
                'parking_exit_at' => (new \DateTimeImmutable($occurredAt))->format('Y-m-d H:i:s'),
            ], 'authoritative_guest_pickup_confirmed', $actorUserId);
            $this->plans()->invalidateForWrite((int) $checklist['fleet_vehicle_id'], 'new_actual_movement_event', $actorUserId);
            if ($this->db->transStatus() === false) {
                throw new RuntimeException('Guest pickup confirmation transaction failed.');
            }
            $this->db->transCommit();

            return true;
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }

    private function rejectDuplicateHandoff(array $checklist): void
    {
        if ($this->events->activeForTrip((int) $checklist['turo_trip_normalized_id'], ['actual_handoff']) !== null) {
            throw new \InvalidArgumentException('Guest pickup is already confirmed for this movement.');
        }
    }

    private function recordObservation(array $checklist, array $data, int $actorUserId, string $eventCode): bool
    {
        $movementType = (string) $checklist['movement_type'];
        $occurredAt = trim((string) ($data['occurred_at'] ?? ''));
        if ($occurredAt === '') {
            throw new \InvalidArgumentException('Actual time is required.');
        }

        $this->db->transBegin();
        try {
            if ($eventCode === 'actual_handoff') {
                $this->rejectDuplicateHandoff($checklist);
            } elseif ($eventCode === 'actual_return' && $this->events->activeForTrip((int) $checklist['turo_trip_normalized_id'], ['actual_return', 'vehicle_recovered']) !== null) {
                throw new \InvalidArgumentException('This return is already complete. Correct or void the existing fact instead.');
            }
            $eventId = $this->events->record(
                (int) $checklist['fleet_vehicle_id'],
                (int) $checklist['turo_trip_normalized_id'],
                $eventCode,
                $movementType,
                $occurredAt,
                (string) ($data['location_class'] ?? 'unknown'),
                $data['location_detail'] ?? null,
                'checklist_operator',
                $actorUserId,
                $data['note'] ?? null,
                [
                    'garage_code' => $data['airport_garage_code'] ?? null,
                    'level' => $data['airport_parking_level'] ?? null,
                    'row' => $data['airport_parking_row'] ?? null,
                    'structured_input_present' => array_key_exists('airport_garage_code', $data),
                ],
            );
            $this->assessments->record(
                (int) $checklist['fleet_vehicle_id'],
                (int) $checklist['turo_trip_normalized_id'],
                $eventId,
                $movementType,
                ($data['cleanliness'] ?? '') === '' ? null : (string) $data['cleanliness'],
                $data['energy_percent'] ?? null,
                $occurredAt,
                'checklist_operator',
                $actorUserId,
                $data['note'] ?? null,
            );
            if ($eventCode === 'vehicle_staged') {
                $this->syncAirportWorkflow($checklist, [
                    'workflow_status' => 'staged',
                    'vehicle_staged_at' => (new \DateTimeImmutable($occurredAt))->format('Y-m-d H:i:s'),
                    'garage' => $data['airport_garage_code'] ?? null,
                    'parking_level' => $data['airport_parking_level'] ?? null,
                    'parking_row' => $data['airport_parking_row'] ?? null,
                    'operator_notes' => $data['note'] ?? null,
                ], 'authoritative_vehicle_staged', $actorUserId);
            }
            $this->plans()->invalidateForWrite((int) $checklist['fleet_vehicle_id'], 'new_actual_movement_event', $actorUserId);
            if ($this->db->transStatus() === false) {
                throw new RuntimeException('Operational fact transaction failed.');
            }
            $this->db->transCommit();
            return true;
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }

    public function correctForChecklist(array $checklist, array $data, int $actorUserId): bool
    {
        if (! ($checklist['exists'] ?? false)) {
            return false;
        }
        $eventId = (int) ($data['event_id'] ?? 0);
        $assessmentId = (int) ($data['assessment_id'] ?? 0);
        $reason = trim((string) ($data['correction_reason'] ?? ''));
        $event = $this->events->find($eventId);
        $assessment = $this->assessments->find($assessmentId);
        if ($event === null || $assessment === null
            || (int) $event['turo_trip_normalized_id'] !== (int) $checklist['turo_trip_normalized_id']
            || (int) $assessment['turo_trip_normalized_id'] !== (int) $checklist['turo_trip_normalized_id']
            || (int) $assessment['trip_movement_event_id'] !== $eventId) {
            throw new \InvalidArgumentException('The recorded facts do not belong to this movement.');
        }

        $this->db->transBegin();
        try {
            $replacementEventId = $this->events->correct($eventId, [
                'occurred_at' => $this->presentValue($data, 'occurred_at', $event['occurred_at']),
                'location_class' => $this->presentValue($data, 'location_class', $event['location_class']),
                'location_detail' => $this->presentValue($data, 'location_detail', $event['location_detail']),
                'airport_garage_code' => $this->presentValue($data, 'airport_garage_code', $event['airport_garage_code'] ?? null),
                'airport_parking_level' => $this->presentValue($data, 'airport_parking_level', $event['airport_parking_level'] ?? null),
                'airport_parking_row' => $this->presentValue($data, 'airport_parking_row', $event['airport_parking_row'] ?? null),
                'note' => $this->presentValue($data, 'note', $event['note']),
            ], $actorUserId, $reason, false);
            $this->assessments->correct($assessmentId, [
                'trip_movement_event_id' => $replacementEventId,
                'captured_at' => $this->presentValue($data, 'occurred_at', $assessment['captured_at']),
                'cleanliness' => $this->presentValue($data, 'cleanliness', $assessment['cleanliness']),
                'energy_percent' => $this->presentValue($data, 'energy_percent', $assessment['energy_percent']),
                'note' => $this->presentValue($data, 'note', $assessment['note']),
            ], $actorUserId, $reason, false);
            $this->plans()->invalidateForWrite((int) $checklist['fleet_vehicle_id'], 'corrected_actual_movement_event', $actorUserId);
            if ($this->db->transStatus() === false) {
                throw new RuntimeException('Operational fact correction transaction failed.');
            }
            $this->db->transCommit();
            return true;
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }

    /** @return array<int, array<string, mixed>> */
    public function wrongTripCandidates(array $checklist, int $eventId): array
    {
        $event = $this->events->find($eventId);
        if (! ($checklist['exists'] ?? false) || $event === null || (int) $event['turo_trip_normalized_id'] !== (int) $checklist['turo_trip_normalized_id']) {
            return [];
        }
        $movementType = (string) $event['movement_type'];
        $eventCode = (string) $event['event_code'];
        if (! in_array($eventCode, $this->compatibleEventCodes($movementType), true)) {
            return [];
        }
        $scheduleField = $movementType === 'pickup' ? 'starts_at' : 'ends_at';
        $occurredAt = new \DateTimeImmutable((string) $event['occurred_at']);
        $windowHours = $this->movementConfig->repairCandidateWindowHours;

        return array_values(array_filter(
            $this->repo()->vehicleTripHistory((int) $event['fleet_vehicle_id'], 100),
            fn (array $trip): bool => $this->isPlausibleRepairTarget($trip, (int) $checklist['turo_trip_normalized_id'], $scheduleField, $occurredAt, $windowHours)
                && $this->repo()->activeMovementConflict((int) $trip['id'], $this->conflictingEventCodes($eventCode)) === null,
        ));
    }

    /** @return array<int, array<string, mixed>> */
    public function wrongTripConflicts(array $checklist, int $eventId): array
    {
        $event = $this->events->find($eventId);
        if (! ($checklist['exists'] ?? false) || $event === null || (int) $event['turo_trip_normalized_id'] !== (int) $checklist['turo_trip_normalized_id']) {
            return [];
        }
        $movementType = (string) $event['movement_type'];
        $eventCode = (string) $event['event_code'];
        if (! in_array($eventCode, $this->compatibleEventCodes($movementType), true)) {
            return [];
        }
        $scheduleField = $movementType === 'pickup' ? 'starts_at' : 'ends_at';
        $occurredAt = new \DateTimeImmutable((string) $event['occurred_at']);
        $windowHours = $this->movementConfig->repairCandidateWindowHours;
        $conflicts = [];
        foreach ($this->repo()->vehicleTripHistory((int) $event['fleet_vehicle_id'], 100) as $trip) {
            if (! $this->isPlausibleRepairTarget($trip, (int) $checklist['turo_trip_normalized_id'], $scheduleField, $occurredAt, $windowHours)) {
                continue;
            }
            $conflict = $this->repo()->activeMovementConflict((int) $trip['id'], $this->conflictingEventCodes($eventCode));
            if ($conflict !== null) {
                $conflicts[] = array_merge($trip, ['conflict_label' => $this->movementConflictLabel((string) $conflict['event_code'])]);
            }
        }

        return $conflicts;
    }

    public function repairWrongTrip(array $checklist, array $data, int $actorUserId): bool
    {
        if (! ($checklist['exists'] ?? false)) {
            return false;
        }
        $eventId = (int) ($data['event_id'] ?? 0);
        $assessmentId = (int) ($data['assessment_id'] ?? 0);
        $targetTripId = (int) ($data['target_trip_id'] ?? 0);
        $reason = trim((string) ($data['repair_reason'] ?? ''));
        if ($reason === '') {
            throw new \InvalidArgumentException('A repair reason is required.');
        }
        $event = $this->events->find($eventId);
        $assessment = $this->assessments->find($assessmentId);
        if ($event === null || $assessment === null
            || (int) $event['turo_trip_normalized_id'] !== (int) $checklist['turo_trip_normalized_id']
            || (int) $assessment['trip_movement_event_id'] !== $eventId) {
            throw new \InvalidArgumentException('The recorded facts do not belong to this movement.');
        }
        $compatibleEvents = $this->compatibleEventCodes((string) $event['movement_type']);
        if (! in_array($event['event_code'], $compatibleEvents, true)) {
            throw new \InvalidArgumentException('This fact is not compatible with a reservation movement repair.');
        }
        $targetTrip = $this->repo()->tripSchedule($targetTripId);
        $scheduleField = (string) $event['movement_type'] === 'pickup' ? 'starts_at' : 'ends_at';
        $isPlausibleTarget = $targetTrip !== null
            && (int) $targetTrip['fleet_vehicle_id'] === (int) $event['fleet_vehicle_id']
            && $this->isPlausibleRepairTarget(
                $targetTrip,
                (int) $checklist['turo_trip_normalized_id'],
                $scheduleField,
                new \DateTimeImmutable((string) $event['occurred_at']),
                $this->movementConfig->repairCandidateWindowHours,
            );
        if ($isPlausibleTarget) {
            $conflict = $this->repo()->activeMovementConflict($targetTripId, $this->conflictingEventCodes((string) $event['event_code']));
            if ($conflict !== null) {
                throw new \InvalidArgumentException('The selected trip already has ' . $this->movementConflictLabel((string) $conflict['event_code']) . '. Resolve that active movement fact before repairing this observation.');
            }
        }
        $candidateIds = array_map(static fn (array $trip): int => (int) $trip['id'], $this->wrongTripCandidates($checklist, $eventId));
        if (! in_array($targetTripId, $candidateIds, true)) {
            throw new \InvalidArgumentException('Choose a plausible nearby trip for the same vehicle without a conflicting fact.');
        }

        $auditReason = 'Recorded on wrong trip: ' . $reason;
        $this->db->transBegin();
        try {
            $replacementEventId = $this->events->correct($eventId, [
                'turo_trip_normalized_id' => $targetTripId,
                'source' => $event['source'],
                'actor_user_id' => $event['actor_user_id'],
            ], $actorUserId, $auditReason, false);
            $this->assessments->correct($assessmentId, [
                'turo_trip_normalized_id' => $targetTripId,
                'trip_movement_event_id' => $replacementEventId,
                'source' => $assessment['source'],
                'actor_user_id' => $assessment['actor_user_id'],
            ], $actorUserId, $auditReason, false);
            $this->plans()->invalidateForWrite((int) $checklist['fleet_vehicle_id'], 'repaired_actual_movement_event', $actorUserId);
            if ($this->db->transStatus() === false) {
                throw new RuntimeException('Wrong-trip repair transaction failed.');
            }
            $this->db->transCommit();

            return true;
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }

    /** @param array<string, mixed> $trip */
    private function isPlausibleRepairTarget(array $trip, int $sourceTripId, string $scheduleField, \DateTimeImmutable $occurredAt, int $windowHours): bool
    {
        if ((int) $trip['id'] === $sourceTripId || empty($trip[$scheduleField])) {
            return false;
        }
        $status = (string) ($trip['trip_status_code'] ?? '');
        if (str_starts_with($status, 'canceled') || $status === 'invalid') {
            return false;
        }
        $distance = abs((new \DateTimeImmutable((string) $trip[$scheduleField]))->getTimestamp() - $occurredAt->getTimestamp());

        return $distance <= $windowHours * 3600;
    }

    /** @return list<string> */
    private function conflictingEventCodes(string $eventCode): array
    {
        return match ($eventCode) {
            'actual_handoff' => ['actual_handoff'],
            'vehicle_staged' => ['vehicle_staged', 'actual_handoff'],
            'actual_return', 'vehicle_recovered' => ['actual_return', 'vehicle_recovered'],
            default => [],
        };
    }

    /** @return list<string> */
    private function compatibleEventCodes(string $movementType): array
    {
        return match ($movementType) {
            'pickup' => ['vehicle_staged', 'actual_handoff'],
            'return' => ['actual_return', 'vehicle_recovered'],
            default => [],
        };
    }

    private function movementConflictLabel(string $eventCode): string
    {
        return match ($eventCode) {
            'actual_handoff' => 'an active guest handoff',
            'vehicle_staged' => 'an active staging fact',
            'actual_return' => 'an active return fact',
            'vehicle_recovered' => 'an active vehicle recovery fact',
            default => 'an active movement fact',
        };
    }

    private function presentValue(array $data, string $key, mixed $fallback): mixed
    {
        if (! array_key_exists($key, $data) || (is_string($data[$key]) && trim($data[$key]) === '')) {
            return $fallback;
        }

        return $data[$key];
    }

    private function requireEarlyHandoffConfirmation(array $checklist, array $data): void
    {
        $occurredAt = trim((string) ($data['occurred_at'] ?? ''));
        $scheduledAt = trim((string) ($checklist['scheduled_at'] ?? $checklist['starts_at'] ?? ''));
        if ($occurredAt === '' || $scheduledAt === '') {
            return;
        }
        $threshold = $this->movementConfig->earlyHandoffWarningHours;
        $warningBoundary = (new \DateTimeImmutable($scheduledAt))->modify('-' . $threshold . ' hours');
        if (new \DateTimeImmutable($occurredAt) < $warningBoundary && ($data['confirm_early_handoff'] ?? null) !== '1') {
            $scheduledLabel = (new \DateTimeImmutable($scheduledAt))->format('M j, Y \a\t g:i A');
            $message = 'This reservation does not begin until ' . $scheduledLabel . '. The handoff time entered is more than ' . $threshold . ' hours early. Are you sure this is the correct reservation?';
            if (($data['location_class'] ?? null) === 'airport_hnl') {
                $message .= ' If the guest does not yet have possession, use Stage at HNL instead.';
            }

            throw new EarlyHandoffConfirmationRequired($message);
        }
    }

    private function plans(): VehiclePositioningPlanService
    {
        return $this->positioningPlans ?? new VehiclePositioningPlanService(new \App\Repositories\OperationalFactsRepository($this->db));
    }

    private function repo(): \App\Repositories\OperationalFactsRepository
    {
        return new \App\Repositories\OperationalFactsRepository($this->db);
    }

    private function syncAirportWorkflow(array $checklist, array $data, string $action, int $actorUserId): void
    {
        if (! $this->db->tableExists('airport_movement_workflows') || ! in_array('workflow_status', $this->db->getFieldNames('airport_movement_workflows'), true)) {
            return;
        }
        $workflow = $this->db->table('airport_movement_workflows')
            ->select('id')
            ->where('turo_trip_normalized_id', (int) $checklist['turo_trip_normalized_id'])
            ->where('movement_type', 'pickup')
            ->orderBy('scheduled_at', 'DESC')
            ->get(1)
            ->getRowArray();
        if ($workflow !== null) {
            $companyId = (int) ($checklist['company_id'] ?? 0);
            if ($companyId < 1 || ! (new AirportMovementRepository($this->db))->updateWorkflow($companyId, (int) $workflow['id'], $data, $action, $actorUserId)) {
                throw new RuntimeException('Airport workflow synchronization failed.');
            }
        }
    }
}
