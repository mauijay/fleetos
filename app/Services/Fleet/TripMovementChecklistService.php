<?php

namespace App\Services\Fleet;

use App\Repositories\MovementChecklistRepository;
use DateTimeImmutable;

class TripMovementChecklistService
{
    public const RETURN_DISPOSITIONS = ['available', 'needs_cleaning', 'needs_charging', 'maintenance_required', 'claim_review_required', 'offline'];

    public function __construct(
        private readonly ?MovementChecklistRepository $repository = null,
        private readonly MovementChecklistDefinitionService $definitions = new MovementChecklistDefinitionService(),
        private readonly MovementReadinessService $readiness = new MovementReadinessService(),
    ) {
    }

    /** @return array<string, mixed> */
    public function ensureForMovement(array $reservation, string $movementType, bool $isAirport = false): array
    {
        $tripId = (int) ($reservation['id'] ?? 0);
        $vehicleId = (int) ($reservation['fleet_vehicle_id'] ?? 0);
        $scheduledAt = (string) ($movementType === 'return' ? ($reservation['ends_at'] ?? '') : ($reservation['starts_at'] ?? ''));

        $existing = $this->repo()->findByMovement($tripId, $movementType, $scheduledAt);
        if ($existing === null) {
            $checklistId = $this->repo()->createChecklist([
                'turo_trip_normalized_id' => $tripId,
                'fleet_vehicle_id' => $vehicleId,
                'movement_type' => $movementType,
                'scheduled_at' => $scheduledAt,
                'readiness_status' => 'not_started',
            ]);

            foreach ($this->definitions->items($movementType, $isAirport) as $item) {
                $this->repo()->createItem([
                    'trip_movement_checklist_id' => $checklistId,
                    'item_code' => $item['code'],
                    'label' => $item['label'],
                    'is_required' => $item['required'],
                    'is_critical' => $item['critical'],
                    'sort_order' => $item['sortOrder'],
                ]);
            }
        }

        return $this->checklist($this->repo()->findByMovement($tripId, $movementType, $scheduledAt)['id']);
    }

    /** @return array<int, array<string, mixed>> */
    public function ensureForDay(array $today): array
    {
        $checklists = [];

        foreach (($today['todays_pickups'] ?? []) as $pickup) {
            $checklists[] = $this->ensureForMovement($pickup, 'pickup', $this->hasAirportDelivery((int) ($pickup['fleet_vehicle_id'] ?? 0), $today['airport_deliveries'] ?? []));
        }

        foreach (($today['todays_returns'] ?? []) as $return) {
            $checklists[] = $this->ensureForMovement($return, 'return');
        }

        return $checklists;
    }

    /** @return array<string, mixed> */
    public function checklist(int $id): array
    {
        $checklist = $this->repo()->checklist($id);
        if ($checklist === null) {
            return ['exists' => false];
        }

        $items = $this->repo()->items($id);
        $progress = $this->readiness->progress($items);
        $status = $this->readiness->status((string) $checklist['movement_type'], $items, $checklist['vehicle_disposition'] ?? null, $checklist['completed_at'] !== null);

        return array_merge($checklist, ['exists' => true, 'items' => $items, 'progress' => $progress, 'readiness_status' => $status]);
    }

    public function completeItem(int $itemId, ?string $note = null, ?int $actorUserId = null): bool
    {
        return $this->repo()->updateItem($itemId, ['completion_state' => 'complete', 'completion_source' => 'manual', 'completed_at' => date('Y-m-d H:i:s'), 'note' => $note], $actorUserId);
    }

    public function undoItem(int $itemId, ?int $actorUserId = null): bool
    {
        return $this->repo()->updateItem($itemId, ['completion_state' => 'open', 'completion_source' => null, 'completed_at' => null], $actorUserId);
    }

    public function markNotApplicable(int $itemId, ?string $note = null, ?int $actorUserId = null): bool
    {
        return $this->repo()->updateItem($itemId, ['applicability' => 'not_applicable', 'completion_state' => 'not_applicable', 'completion_source' => 'manual', 'completed_at' => null, 'note' => $note], $actorUserId);
    }

    public function setDisposition(int $checklistId, string $disposition, ?int $actorUserId = null): bool
    {
        if (! in_array($disposition, self::RETURN_DISPOSITIONS, true)) {
            return false;
        }

        return $this->repo()->updateChecklist($checklistId, ['vehicle_disposition' => $disposition], $actorUserId);
    }

    public function completeChecklist(int $checklistId, ?string $note = null, ?int $actorUserId = null): bool
    {
        $checklist = $this->checklist($checklistId);
        if (! ($checklist['exists'] ?? false) || ! in_array($checklist['readiness_status'], ['ready'], true)) {
            return false;
        }

        return $this->repo()->updateChecklist($checklistId, ['completed_at' => date('Y-m-d H:i:s'), 'completion_note' => $note, 'readiness_status' => 'completed'], $actorUserId);
    }

    public function reopenChecklist(int $checklistId, ?int $actorUserId = null): bool
    {
        return $this->repo()->updateChecklist($checklistId, ['completed_at' => null, 'completion_note' => null, 'readiness_status' => 'in_progress'], $actorUserId);
    }

    /** @return array<int, array<string, mixed>> */
    public function summariesForDay(DateTimeImmutable $day): array
    {
        return array_map(function (array $summary): array {
            $required = (int) ($summary['required_count'] ?? 0);
            $complete = (int) ($summary['required_complete_count'] ?? 0);
            $remaining = max(0, $required - $complete);

            return array_merge($summary, [
                'required_count' => $required,
                'required_complete_count' => $complete,
                'required_remaining_count' => $remaining,
                'critical_open_count' => (int) ($summary['critical_open_count'] ?? 0),
                'percent' => $required === 0 ? 100 : (int) round(($complete / $required) * 100),
                'status_label' => $remaining === 0 ? 'Ready' : $remaining . ' required item' . ($remaining === 1 ? '' : 's') . ' remaining',
                'href' => '/operations/checklists/' . (int) $summary['id'],
            ]);
        }, $this->repo()->summariesForDate($day->setTime(0, 0)->format('Y-m-d H:i:s'), $day->modify('+1 day')->setTime(0, 0)->format('Y-m-d H:i:s')));
    }

    private function hasAirportDelivery(int $vehicleId, array $deliveries): bool
    {
        foreach ($deliveries as $delivery) {
            if ((int) ($delivery['fleet_vehicle_id'] ?? 0) === $vehicleId) {
                return true;
            }
        }

        return false;
    }

    private function repo(): MovementChecklistRepository
    {
        return $this->repository ?? service('movementChecklistRepository');
    }
}
