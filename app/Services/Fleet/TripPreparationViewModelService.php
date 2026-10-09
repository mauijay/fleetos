<?php

namespace App\Services\Fleet;

/** Keep purchased work in its owning trip workspace, including deferred work. */
class TripPreparationViewModelService
{
    /** @return array{target:list<array<string,mixed>>,future:list<array<string,mixed>>} */
    public function forChecklist(array $checklist, array $extrasByTrip, array $readiness): array
    {
        $targetId = (int) ($checklist['turo_trip_normalized_id'] ?? 0);
        $futureId = (int) ($readiness['next_trip']['id'] ?? 0);
        $targetPhases = ($checklist['movement_type'] ?? null) === 'return' ? ['return', 'entire_trip'] : ['preparation', 'pickup', 'entire_trip'];
        $result = ['target' => [], 'future' => []];
        foreach (['target' => $targetId, 'future' => $futureId] as $collection => $tripId) {
            if ($tripId < 1 || ($collection === 'future' && $tripId === $targetId)) {
                continue;
            }
            $seen = [];
            foreach ($extrasByTrip[$tripId] ?? [] as $row) {
                if ((int) ($row['turo_trip_normalized_id'] ?? 0) !== $tripId
                    || (isset($checklist['company_id'], $row['company_id']) && (int) $row['company_id'] !== (int) $checklist['company_id'])) {
                    continue;
                }
                $identity = (int) ($row['selection_id'] ?? $row['id'] ?? 0);
                if (isset($seen[$identity])) {
                    continue;
                }
                $seen[$identity] = true;
                $phases = $collection === 'target' ? $targetPhases : ['preparation', 'pickup', 'entire_trip'];
                if (($row['fulfillment_phase'] ?? null) !== null && ! in_array((string) $row['fulfillment_phase'], $phases, true)) {
                    continue;
                }
                if (! in_array((string) ($row['fulfillment_phase'] ?? ''), $phases, true) && ! ($row['is_informational'] ?? false)) {
                    if ($collection === 'future' && ($row['is_mapped'] ?? false)) {
                        continue;
                    }
                    $row['is_actionable'] = false;
                }
                foreach ($readiness['requirements'] ?? [] as $requirement) {
                    if (($requirement['source_type'] ?? null) === 'extra_fulfillment'
                        && (int) $requirement['trip_id'] === $tripId
                        && (int) $requirement['fulfillment_id'] === (int) ($row['fulfillment_id'] ?? 0)) {
                        $row['is_actionable'] = $row['is_actionable'] && $requirement['actionable'];
                        $row['is_readiness_blocking'] = MovementReadinessProjectionService::isBlocking($requirement);
                        $row['deferred_label'] = $requirement['deferred_label'] ?? null;
                        $row['retired_reason'] = $requirement['retired_reason'] ?? null;
                    }
                }
                $result[$collection][] = $row;
            }
        }

        return $result;
    }
}
