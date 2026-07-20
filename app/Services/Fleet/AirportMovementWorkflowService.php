<?php

namespace App\Services\Fleet;

use App\Repositories\AirportMovementRepository;
use DateTimeImmutable;

class AirportMovementWorkflowService
{
    public const RESPONSIBILITIES = ['host_operational_cost', 'guest_reimbursable', 'included_in_delivery', 'waived', 'unknown'];

    public function __construct(
        private readonly ?AirportMovementRepository $repository = null,
        private readonly ?TripMovementChecklistService $checklists = null,
        private readonly AirportInstructionService $instructions = new AirportInstructionService(),
    ) {
    }

    /** @return array<int, array<string, mixed>> */
    public function ensureForDay(DateTimeImmutable $day): array
    {
        $deliveries = $this->repo()->airportDeliveriesBetween($day->setTime(0, 0)->format('Y-m-d H:i:s'), $day->modify('+1 day')->setTime(0, 0)->format('Y-m-d H:i:s'));
        $workflows = [];

        foreach ($deliveries as $delivery) {
            $workflows[] = $this->ensure($delivery, 'pickup', (string) ($delivery['scheduled_at'] ?? $delivery['trip_starts_at']));
            if (($delivery['trip_ends_at'] ?? null) !== null) {
                $workflows[] = $this->ensure($delivery, 'return', (string) $delivery['trip_ends_at']);
            }
        }

        return $workflows;
    }

    /** @return array<string, mixed> */
    public function ensure(array $delivery, string $movementType, string $scheduledAt): array
    {
        $tripId = (int) ($delivery['turo_trip_normalized_id'] ?? 0);
        if ($tripId <= 0 || ! in_array($movementType, ['pickup', 'return'], true)) {
            return ['exists' => false, 'message' => 'Airport workflow requires a linked trip and valid movement type.'];
        }

        $existing = $this->repo()->findWorkflow($tripId, $movementType, $scheduledAt);
        if ($existing === null) {
            $checklist = $this->checklists()->ensureForMovement([
                'id' => $tripId,
                'fleet_vehicle_id' => (int) $delivery['fleet_vehicle_id'],
                'starts_at' => (string) ($delivery['trip_starts_at'] ?? $delivery['scheduled_at']),
                'ends_at' => (string) ($delivery['trip_ends_at'] ?? $delivery['scheduled_at']),
            ], $movementType, true);

            $id = $this->repo()->createWorkflow([
                'airport_delivery_id' => (int) $delivery['id'],
                'turo_trip_normalized_id' => $tripId,
                'trip_movement_checklist_id' => (int) $checklist['id'],
                'fleet_vehicle_id' => (int) $delivery['fleet_vehicle_id'],
                'airport_id' => (int) $delivery['airport_id'],
                'movement_type' => $movementType,
                'scheduled_at' => $scheduledAt,
                'garage' => 'HNL International Parking Garage',
                'parking_cost_responsibility' => 'unknown',
            ]);

            $existing = $this->repo()->workflow($id);
        }

        return $this->view($existing);
    }

    /** @return array<string, mixed> */
    public function workflow(int $id): array
    {
        $workflow = $this->repo()->workflow($id);

        return $workflow === null ? ['exists' => false] : $this->view($workflow);
    }

    public function recordStaging(int $id, array $data): bool
    {
        $payload = $this->clean($data, ['garage', 'terminal', 'airline_or_flight', 'parking_level', 'parking_zone', 'parking_row', 'parking_stall', 'parking_entry_at', 'parking_access_method', 'parking_ticket_location', 'operator_notes']);
        return $this->repo()->updateWorkflow($id, array_merge($payload, ['workflow_status' => 'preparing']), 'staging_recorded');
    }

    public function markStaged(int $id, array $confirmations): bool
    {
        foreach (['vehicle_parked', 'vehicle_locked', 'key_card_placed', 'parking_details_verified'] as $required) {
            if (($confirmations[$required] ?? null) !== '1') {
                return false;
            }
        }

        $ok = $this->repo()->updateWorkflow($id, ['workflow_status' => 'staged', 'vehicle_staged_at' => date('Y-m-d H:i:s'), 'vehicle_locked_at' => date('Y-m-d H:i:s'), 'key_card_confirmed_at' => date('Y-m-d H:i:s')], 'vehicle_staged');
        $workflow = $this->repo()->workflow($id);
        if ($ok && $workflow !== null && $workflow['trip_movement_checklist_id'] !== null) {
            $this->completeChecklistItem((int) $workflow['trip_movement_checklist_id'], 'airport_staging_completed');
            $this->completeChecklistItem((int) $workflow['trip_movement_checklist_id'], 'parking_location_recorded');
        }

        return $ok;
    }

    public function markInstructionsSent(int $id): bool
    {
        $workflow = $this->repo()->workflow($id);
        if ($workflow === null) {
            return false;
        }
        $instruction = $workflow['movement_type'] === 'return' ? $this->instructions->returnInstructions($workflow) : $this->instructions->pickupInstructions($workflow);
        if (! $instruction['complete']) {
            return false;
        }

        $ok = $this->repo()->updateWorkflow($id, ['workflow_status' => 'instructions_sent', 'guest_instructions' => $instruction['text'], 'guest_instructions_sent_at' => date('Y-m-d H:i:s')], 'instructions_sent');
        if ($ok && $workflow['trip_movement_checklist_id'] !== null) {
            $this->completeChecklistItem((int) $workflow['trip_movement_checklist_id'], 'guest_pickup_instructions_confirmed');
            $this->completeChecklistItem((int) $workflow['trip_movement_checklist_id'], 'turo_access_instructions_confirmed');
        }

        return $ok;
    }

    public function confirmGuestPickup(int $id): bool
    {
        $workflow = $this->repo()->workflow($id);
        if ($workflow === null || ! in_array($workflow['workflow_status'], ['staged', 'instructions_sent', 'guest_pickup_pending'], true)) {
            return false;
        }

        return $this->repo()->updateWorkflow($id, ['workflow_status' => 'picked_up', 'guest_pickup_confirmed_at' => date('Y-m-d H:i:s'), 'parking_exit_at' => date('Y-m-d H:i:s')], 'guest_pickup_confirmed');
    }

    public function recordReturnLocation(int $id, array $data): bool
    {
        $payload = $this->clean($data, ['guest_reported_level', 'guest_reported_zone', 'guest_reported_row', 'guest_reported_stall', 'guest_note', 'parking_ticket_location']);
        return $this->repo()->updateWorkflow($id, array_merge($payload, ['workflow_status' => 'returned', 'return_location_reported_at' => date('Y-m-d H:i:s')]), 'return_location_recorded');
    }

    public function confirmVehicleLocated(int $id): bool
    {
        $workflow = $this->repo()->workflow($id);
        if ($workflow === null) {
            return false;
        }
        $ok = $this->repo()->updateWorkflow($id, ['workflow_status' => 'vehicle_located', 'vehicle_recovered_at' => date('Y-m-d H:i:s')], 'vehicle_located');
        if ($ok && $workflow['trip_movement_checklist_id'] !== null) {
            $this->completeChecklistItem((int) $workflow['trip_movement_checklist_id'], 'vehicle_received');
        }

        return $ok;
    }

    public function recordParkingCost(int $id, ?string $actualCost, string $responsibility): bool
    {
        if ($actualCost !== null && $actualCost !== '' && ! is_numeric($actualCost)) {
            return false;
        }
        if (! in_array($responsibility, self::RESPONSIBILITIES, true)) {
            return false;
        }

        return $this->repo()->updateWorkflow($id, ['actual_parking_cost_amount' => $actualCost === '' ? null : $actualCost, 'parking_cost_responsibility' => $responsibility], 'parking_cost_recorded');
    }

    public function complete(int $id): bool
    {
        $workflow = $this->repo()->workflow($id);
        if ($workflow === null) {
            return false;
        }
        if ($workflow['trip_movement_checklist_id'] !== null) {
            $checklist = $this->checklists()->checklist((int) $workflow['trip_movement_checklist_id']);
            if (! in_array($checklist['readiness_status'] ?? '', ['ready', 'completed'], true)) {
                return false;
            }
        }

        return $this->repo()->updateWorkflow($id, ['workflow_status' => 'completed', 'completed_at' => date('Y-m-d H:i:s')], 'workflow_completed');
    }

    public function createException(int $id, string $type, string $severity, string $note): int
    {
        $this->repo()->updateWorkflow($id, ['workflow_status' => 'exception'], 'exception_created');

        return $this->repo()->createException($id, $type, $severity, $note);
    }

    /** @return array<int, array<string, mixed>> */
    public function today(DateTimeImmutable $day, array $filters = []): array
    {
        $this->ensureForDay($day);
        $workflows = $this->repo()->workflowsBetween($day->setTime(0, 0)->format('Y-m-d H:i:s'), $day->modify('+1 day')->setTime(0, 0)->format('Y-m-d H:i:s'));
        if (($filters['status'] ?? '') !== '') {
            $workflows = array_values(array_filter($workflows, static fn (array $workflow): bool => $workflow['workflow_status'] === $filters['status']));
        }

        return array_map(fn (array $workflow): array => $this->view($workflow), $workflows);
    }

    /** @return array<string, int|bool|string> */
    public function attentionSummary(?DateTimeImmutable $day = null): array
    {
        $day ??= new DateTimeImmutable();
        $workflows = $this->today($day);
        $needsAction = array_values(array_filter($workflows, static fn (array $workflow): bool => ! in_array($workflow['workflow_status'], ['completed', 'picked_up'], true)));

        return ['airport_workflows_requiring_action' => count($needsAction), 'has_airport_work' => count($needsAction) > 0, 'href' => '/operations/airport'];
    }

    /** @return array<string, mixed> */
    private function view(array $workflow): array
    {
        $instruction = $workflow['movement_type'] === 'return' ? $this->instructions->returnInstructions($workflow) : $this->instructions->pickupInstructions($workflow);
        return array_merge($workflow, ['exists' => true, 'instruction' => $instruction, 'exceptions' => $this->repo()->openExceptions((int) $workflow['id']), 'href' => '/operations/airport/' . (int) $workflow['id']]);
    }

    private function completeChecklistItem(int $checklistId, string $itemCode): void
    {
        $checklist = $this->checklists()->checklist($checklistId);
        foreach (($checklist['items'] ?? []) as $item) {
            if ($item['item_code'] === $itemCode) {
                $this->checklists()->completeItem((int) $item['id'], 'Completed from airport workflow milestone.');
            }
        }
    }

    private function clean(array $data, array $keys): array
    {
        $clean = [];
        foreach ($keys as $key) {
            if (isset($data[$key]) && trim((string) $data[$key]) !== '') {
                $clean[$key] = trim((string) $data[$key]);
            }
        }
        return $clean;
    }

    private function repo(): AirportMovementRepository
    {
        return $this->repository ?? service('airportMovementRepository');
    }

    private function checklists(): TripMovementChecklistService
    {
        return $this->checklists ?? service('tripMovementChecklistService');
    }
}
