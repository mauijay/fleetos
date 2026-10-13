<?php

namespace App\Services\Fleet;

/** Exact membership presentation; the slot supplies warnings, never a replacement target. */
final class VehicleWheelRepairPresentation
{
    public static function contexts(array $conditions, array $projection): array
    {
        $result = [];
        foreach ($conditions as $condition) {
            if (! VehicleWheelEpisodeGuard::participates($condition)) {
                continue;
            }
            $code = VehicleWheelIdentity::code($condition['panel_code']);
            $slot = $projection['wheels'][$code];
            $id = (int) $condition['id'];
            $conflict = $slot['state'] === 'conflict' || ! $projection['available'];
            $result[$id] = [
                'label' => $code . ' Wheel — curb rash',
                'episode' => 'Condition episode #' . $id,
                'history_url' => $slot['history_url'] . '?episode=' . $id,
                'selectable' => ! $conflict && $slot['active_condition_id'] === $id,
                'reopen_blocked' => $conflict || ($slot['active_condition_id'] !== null && $slot['active_condition_id'] !== $id),
                'warning' => $conflict ? 'Review conflicting records' : ($slot['active_condition_id'] !== null && $slot['active_condition_id'] !== $id ? 'A later wheel episode occupies this slot. This job still targets its original episode.' : null),
            ];
        }
        return $result;
    }
}
