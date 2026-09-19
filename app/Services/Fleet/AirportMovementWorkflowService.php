<?php

namespace App\Services\Fleet;

use App\Repositories\AirportMovementRepository;
use CodeIgniter\Exceptions\PageNotFoundException;
use DateTimeImmutable;

class AirportMovementWorkflowService
{
    public const RESPONSIBILITIES = ['host_operational_cost', 'guest_reimbursable', 'included_in_delivery', 'waived', 'unknown'];

    public function __construct(
        private readonly ?AirportMovementRepository $repository = null,
        private readonly ?TripMovementChecklistService $checklists = null,
        private readonly AirportInstructionService $instructions = new AirportInstructionService(),
        private readonly HnlGarageCatalog $hnlGarages = new HnlGarageCatalog(),
    ) {
    }

    /** @return array<int, array<string, mixed>> */
    public function ensureForDay(int $companyId, DateTimeImmutable $day): array
    {
        $deliveries = $this->repo()->airportDeliveriesBetween($companyId, $day->setTime(0, 0)->format('Y-m-d H:i:s'), $day->modify('+1 day')->setTime(0, 0)->format('Y-m-d H:i:s'));
        $workflows = [];

        foreach ($deliveries as $delivery) {
            $workflows[] = $this->ensure($companyId, $delivery, 'pickup', (string) ($delivery['scheduled_at'] ?? $delivery['trip_starts_at']));
            if (($delivery['trip_ends_at'] ?? null) !== null) {
                $workflows[] = $this->ensure($companyId, $delivery, 'return', (string) $delivery['trip_ends_at']);
            }
        }

        return $workflows;
    }

    /** @return array<int, array<string, mixed>> */
    public function deliveriesForTrip(int $companyId, int $tripId): array
    {
        return $this->repo()->airportDeliveriesForTrip($companyId, $tripId);
    }

    /**
     * @phpstan-impure
     * @return array<string, mixed>|null
     */
    public function existingForMovement(int $companyId, int $tripId, string $movementType, string $scheduledAt): ?array
    {
        return $this->repo()->findWorkflow($companyId, $tripId, $movementType, $scheduledAt);
    }

    /** @return array<string, mixed> */
    public function ensure(int $companyId, array $delivery, string $movementType, string $scheduledAt): array
    {
        $tripId = (int) ($delivery['turo_trip_normalized_id'] ?? 0);
        if ($tripId <= 0 || ! in_array($movementType, ['pickup', 'return'], true)) {
            return ['exists' => false, 'message' => 'Airport workflow requires a linked trip and valid movement type.'];
        }

        $existing = $this->repo()->findWorkflow($companyId, $tripId, $movementType, $scheduledAt);
        if ($existing === null) {
            $checklist = $this->checklists()->ensureForMovement([
                'id' => $tripId,
                'fleet_vehicle_id' => (int) $delivery['fleet_vehicle_id'],
                'starts_at' => (string) ($delivery['trip_starts_at'] ?? $delivery['scheduled_at']),
                'ends_at' => (string) ($delivery['trip_ends_at'] ?? $delivery['scheduled_at']),
            ], $movementType, true);

            if ($this->checklists()->checklistForCompany($companyId, (int) $checklist['id']) === null) {
                throw new \InvalidArgumentException('Airport workflow checklist relationship is invalid.');
            }

            $id = $this->repo()->createWorkflow($companyId, [
                'airport_delivery_id' => (int) $delivery['id'],
                'turo_trip_normalized_id' => $tripId,
                'trip_movement_checklist_id' => (int) $checklist['id'],
                'fleet_vehicle_id' => (int) $delivery['fleet_vehicle_id'],
                'airport_id' => (int) $delivery['airport_id'],
                'movement_type' => $movementType,
                'scheduled_at' => $scheduledAt,
                'garage' => 'international',
                'parking_cost_responsibility' => 'unknown',
            ]);

            $existing = $this->repo()->workflow($companyId, $id);
        }

        return $this->view($companyId, $existing);
    }

    /** @return array<string, mixed> */
    public function workflow(int $companyId, int $id): array
    {
        return $this->view($companyId, $this->requireWorkflow($companyId, $id));
    }

    public function recordStaging(int $companyId, int $id, array $data, ?int $actorUserId = null): bool
    {
        $this->requireWorkflow($companyId, $id);
        $parking = $this->hnlGarages->validate($data['garage'] ?? null, $data['parking_level'] ?? null, $data['parking_row'] ?? null);
        $payload = $this->clean($data, ['terminal', 'airline_or_flight', 'parking_zone', 'parking_entry_at', 'parking_access_method', 'parking_ticket_location', 'operator_notes']);
        $payload = array_merge($payload, [
            'garage' => $parking['garage_code'],
            'parking_level' => (string) $parking['level'],
            'parking_row' => $parking['row'],
        ]);
        return $this->repo()->updateWorkflow($companyId, $id, array_merge($payload, ['workflow_status' => 'preparing']), 'staging_recorded', $actorUserId);
    }

    public function markStaged(int $companyId, int $id, array $confirmations, ?int $actorUserId = null): bool
    {
        $workflow = $this->requireWorkflow($companyId, $id);
        foreach (['vehicle_parked', 'vehicle_locked', 'key_card_placed', 'parking_details_verified'] as $required) {
            if (($confirmations[$required] ?? null) !== '1') {
                return false;
            }
        }

        return (bool) $this->repo()->transaction(function () use ($companyId, $id, $actorUserId): bool {
            $workflow = $this->requireWorkflow($companyId, $id);
            $this->requireLinkedChecklist($companyId, $workflow);
            $ok = $this->repo()->updateWorkflow($companyId, $id, ['workflow_status' => 'staged', 'vehicle_staged_at' => date('Y-m-d H:i:s'), 'vehicle_locked_at' => date('Y-m-d H:i:s'), 'key_card_confirmed_at' => date('Y-m-d H:i:s')], 'vehicle_staged', $actorUserId);
            if ($ok && $workflow['trip_movement_checklist_id'] !== null) {
                $this->completeChecklistItem($companyId, (int) $workflow['trip_movement_checklist_id'], 'airport_staging_completed', $actorUserId);
                $this->completeChecklistItem($companyId, (int) $workflow['trip_movement_checklist_id'], 'parking_location_recorded', $actorUserId);
            }

            return $ok;
        });
    }

    public function markInstructionsSent(int $companyId, int $id, ?int $actorUserId = null): bool
    {
        $workflow = $this->requireWorkflow($companyId, $id);
        $instruction = $workflow['movement_type'] === 'return' ? $this->instructions->returnInstructions($workflow) : $this->instructions->pickupInstructions($workflow);
        if (! $instruction['complete']) {
            return false;
        }

        return (bool) $this->repo()->transaction(function () use ($companyId, $id, $instruction, $actorUserId): bool {
            $workflow = $this->requireWorkflow($companyId, $id);
            $this->requireLinkedChecklist($companyId, $workflow);
            $ok = $this->repo()->updateWorkflow($companyId, $id, ['workflow_status' => 'instructions_sent', 'guest_instructions' => $instruction['text'], 'guest_instructions_sent_at' => date('Y-m-d H:i:s')], 'instructions_sent', $actorUserId);
            if ($ok && $workflow['trip_movement_checklist_id'] !== null) {
                $this->completeChecklistItem($companyId, (int) $workflow['trip_movement_checklist_id'], 'guest_pickup_instructions_confirmed', $actorUserId);
                $this->completeChecklistItem($companyId, (int) $workflow['trip_movement_checklist_id'], 'turo_access_instructions_confirmed', $actorUserId);
            }

            return $ok;
        });
    }

    public function confirmGuestPickup(int $companyId, int $id, ?int $actorUserId = null): bool
    {
        $workflow = $this->requireWorkflow($companyId, $id);
        if (! in_array($workflow['workflow_status'], ['staged', 'instructions_sent', 'guest_pickup_pending'], true)) {
            return false;
        }

        return $this->repo()->updateWorkflow($companyId, $id, ['workflow_status' => 'picked_up', 'guest_pickup_confirmed_at' => date('Y-m-d H:i:s'), 'parking_exit_at' => date('Y-m-d H:i:s')], 'guest_pickup_confirmed', $actorUserId);
    }

    public function recordReturnLocation(int $companyId, int $id, array $data, ?int $actorUserId = null): bool
    {
        $this->requireWorkflow($companyId, $id);
        $payload = $this->clean($data, ['guest_reported_level', 'guest_reported_zone', 'guest_reported_row', 'guest_note', 'parking_ticket_location']);
        return $this->repo()->updateWorkflow($companyId, $id, array_merge($payload, ['workflow_status' => 'returned', 'return_location_reported_at' => date('Y-m-d H:i:s')]), 'return_location_recorded', $actorUserId);
    }

    public function confirmVehicleLocated(int $companyId, int $id, ?int $actorUserId = null): bool
    {
        $workflow = $this->requireWorkflow($companyId, $id);
        return (bool) $this->repo()->transaction(function () use ($companyId, $id, $actorUserId): bool {
            $workflow = $this->requireWorkflow($companyId, $id);
            $this->requireLinkedChecklist($companyId, $workflow);
            $ok = $this->repo()->updateWorkflow($companyId, $id, ['workflow_status' => 'vehicle_located', 'vehicle_recovered_at' => date('Y-m-d H:i:s')], 'vehicle_located', $actorUserId);
            if ($ok && $workflow['trip_movement_checklist_id'] !== null) {
                $this->completeChecklistItem($companyId, (int) $workflow['trip_movement_checklist_id'], 'vehicle_received', $actorUserId);
            }

            return $ok;
        });
    }

    public function recordParkingCost(int $companyId, int $id, ?string $actualCost, string $responsibility, ?int $actorUserId = null): bool
    {
        $this->requireWorkflow($companyId, $id);
        if ($actualCost !== null && $actualCost !== '' && ! is_numeric($actualCost)) {
            return false;
        }
        if (! in_array($responsibility, self::RESPONSIBILITIES, true)) {
            return false;
        }

        return $this->repo()->updateWorkflow($companyId, $id, ['actual_parking_cost_amount' => $actualCost === '' ? null : $actualCost, 'parking_cost_responsibility' => $responsibility], 'parking_cost_recorded', $actorUserId);
    }

    public function complete(int $companyId, int $id, ?int $actorUserId = null): bool
    {
        $workflow = $this->requireWorkflow($companyId, $id);
        if ($workflow['trip_movement_checklist_id'] !== null) {
            $checklist = $this->checklists()->checklistForCompany($companyId, (int) $workflow['trip_movement_checklist_id']);
            if ($checklist === null) {
                throw PageNotFoundException::forPageNotFound();
            }
            if (! in_array($checklist['readiness_status'] ?? '', ['ready', 'completed'], true)) {
                return false;
            }
        }

        return $this->repo()->updateWorkflow($companyId, $id, ['workflow_status' => 'completed', 'completed_at' => date('Y-m-d H:i:s')], 'workflow_completed', $actorUserId);
    }

    public function createException(int $companyId, int $id, string $type, string $severity, string $note, ?int $actorUserId = null): int
    {
        $this->requireWorkflow($companyId, $id);

        return $this->repo()->transaction(function () use ($companyId, $id, $type, $severity, $note, $actorUserId): int {
            if (! $this->repo()->updateWorkflow($companyId, $id, ['workflow_status' => 'exception'], 'exception_created', $actorUserId)) {
                return 0;
            }

            return $this->repo()->createException($companyId, $id, $type, $severity, $note);
        });
    }

    /** @return array<int, array<string, mixed>> */
    public function today(int $companyId, DateTimeImmutable $day, array $filters = []): array
    {
        $workflows = $this->repo()->workflowsBetween($companyId, $day->setTime(0, 0)->format('Y-m-d H:i:s'), $day->modify('+1 day')->setTime(0, 0)->format('Y-m-d H:i:s'));
        if (($filters['status'] ?? '') !== '') {
            $workflows = array_values(array_filter($workflows, static fn (array $workflow): bool => $workflow['workflow_status'] === $filters['status']));
        }

        return array_map(fn (array $workflow): array => $this->view($companyId, $workflow), $workflows);
    }

    /** @return array<string, int|bool|string> */
    public function attentionSummary(int $companyId, ?DateTimeImmutable $day = null): array
    {
        $day ??= new DateTimeImmutable();
        $workflows = $this->today($companyId, $day);
        $needsAction = array_values(array_filter($workflows, static fn (array $workflow): bool => ! in_array($workflow['workflow_status'], ['completed', 'picked_up'], true)));

        return ['airport_workflows_requiring_action' => count($needsAction), 'has_airport_work' => count($needsAction) > 0, 'href' => '/operations/airport'];
    }

    /** @return array<string, mixed> */
    private function view(int $companyId, array $workflow): array
    {
        $instruction = $workflow['movement_type'] === 'return' ? $this->instructions->returnInstructions($workflow) : $this->instructions->pickupInstructions($workflow);
        $parking = null;
        try {
            if (trim((string) ($workflow['garage'] ?? '')) !== '' && trim((string) ($workflow['parking_level'] ?? '')) !== '' && trim((string) ($workflow['parking_row'] ?? '')) !== '') {
                $parking = $this->hnlGarages->validate($workflow['garage'], $workflow['parking_level'], $workflow['parking_row']);
            }
        } catch (\InvalidArgumentException) {
            $parking = null;
        }
        $presentation = $parking === null ? null : $this->hnlGarages->presentation($parking['garage_code'], $parking['level'], $parking['row']);

        return array_merge($workflow, [
            'exists' => true,
            'instruction' => $instruction,
            'exceptions' => $this->repo()->openExceptions($companyId, (int) $workflow['id']),
            'href' => '/operations/airport/' . (int) $workflow['id'],
            'garage_definitions' => $this->hnlGarages->definitions(),
            'airport_parking' => $parking,
            'garage_line' => $presentation['garage_line'] ?? null,
            'position_line' => $presentation['position_line'] ?? null,
            'location_label' => $presentation['location_label'] ?? null,
            'approved_turo_garage' => $presentation['approved_turo_garage'] ?? null,
            'garage_attention' => ($presentation['approved_turo_garage'] ?? null) === false ? 'Wrong airport garage - recovery / relocation required' : null,
        ]);
    }

    private function completeChecklistItem(int $companyId, int $checklistId, string $itemCode, ?int $actorUserId): void
    {
        $checklist = $this->checklists()->checklistForCompany($companyId, $checklistId);
        if ($checklist === null) {
            throw PageNotFoundException::forPageNotFound();
        }
        foreach (($checklist['items'] ?? []) as $item) {
            if ($item['item_code'] === $itemCode) {
                $this->checklists()->completeItemForCompany($companyId, (int) $item['id'], 'Completed from airport workflow milestone.', $actorUserId);
            }
        }
    }

    /** @return array<string, mixed> */
    private function requireWorkflow(int $companyId, int $id): array
    {
        $workflow = $this->repo()->workflow($companyId, $id);
        if ($workflow === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        return $workflow;
    }

    /** @param array<string, mixed> $workflow */
    private function requireLinkedChecklist(int $companyId, array $workflow): void
    {
        $checklistId = (int) ($workflow['trip_movement_checklist_id'] ?? 0);
        if ($checklistId > 0 && $this->checklists()->checklistForCompany($companyId, $checklistId) === null) {
            throw PageNotFoundException::forPageNotFound();
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
