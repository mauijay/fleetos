<?php

namespace App\Services\Fleet;

class ChecklistActionFocusService
{
    /** @param array<string, mixed> $projection */
    public function nextAnchor(array $projection): string
    {
        $items = array_column($projection['workflow_history']['legacy_items'] ?? [], null, 'item_code');
        $pending = array_values(array_filter(
            $projection['requirements'] ?? [],
            static fn (array $requirement): bool =>
            ($requirement['status'] ?? null) === MovementReadinessProjectionService::STATUS_UNSATISFIED
            && ($requirement['actionable'] ?? true)
            && ($requirement['action'] ?? null) !== null,
        ));
        usort($pending, static function (array $left, array $right) use ($items): int {
            $priority = static fn (array $requirement): int => ($requirement['blocking'] ?? false)
                ? 0
                : (($items[$requirement['code'] ?? '']['is_required'] ?? false) ? 1 : 2);

            return $priority($left) <=> $priority($right);
        });

        return $pending === [] ? 'readiness-heading' : $this->actionAnchor((string) $pending[0]['code']);
    }

    public function actionAnchor(string $code): string
    {
        return 'checklist-action-' . preg_replace('/[^a-z0-9_-]/', '-', strtolower($code));
    }
}
