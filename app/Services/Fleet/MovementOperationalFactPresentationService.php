<?php

namespace App\Services\Fleet;

use App\Repositories\OperationalFactsRepository;

class MovementOperationalFactPresentationService
{
    public function __construct(
        private readonly ?OperationalFactsRepository $repository = null,
        private readonly HnlGarageCatalog $hnlGarages = new HnlGarageCatalog(),
    ) {
    }

    /** @return array<string, mixed>|null */
    public function latestForTrip(int $tripId): ?array
    {
        $facts = $this->repo()->latestActiveFactsForTrip($tripId);
        return $facts === null ? null : $this->present($facts);
    }

    /** @return array{pickup:array<string, mixed>|null,return:array<string, mixed>|null} */
    public function tripFacts(int $tripId): array
    {
        $presented = ['pickup' => null, 'return' => null];
        foreach ($this->repo()->activeFactsForTrip($tripId) as $facts) {
            $movementType = (string) ($facts['movement_type'] ?? '');
            if (array_key_exists($movementType, $presented) && $presented[$movementType] === null) {
                $presented[$movementType] = $this->present($facts);
            }
        }

        return $presented;
    }

    /** @return array<string, mixed> */
    private function present(array $facts): array
    {

        $eventCode = (string) $facts['event_code'];
        $isHandoff = $eventCode === 'actual_handoff';
        $conditionEvidence = $isHandoff ? $this->handoffConditionEvidence($facts) : null;
        $energyKind = (string) ($facts['energy_kind'] ?? 'unknown');
        $energyLabel = in_array($energyKind, ['gasoline', 'diesel'], true) ? 'Fuel' : (in_array($energyKind, ['electric'], true) ? 'Charge' : 'Energy');
        $locationClass = (string) ($facts['location_class'] ?? 'unknown');
        $locationLabels = [
            'home' => 'Home',
            'airport_hnl' => 'Airport HNL',
            'waikiki_hotel' => 'Waikiki Hotel',
            'other_delivery' => 'Other delivery',
            'unknown' => 'Unknown',
        ];
        $locationDetail = trim((string) ($facts['location_detail'] ?? ''));
        $parking = $this->airportParking($facts);
        $parkingPresentation = $parking === null ? null : $this->hnlGarages->presentation($parking['garage_code'], $parking['level'], $parking['row']);

        return array_merge($facts, [
            'event_title' => $isHandoff ? 'Guest handoff recorded' : ($eventCode === 'vehicle_staged' ? 'Staged for pickup' : ($eventCode === 'actual_return' ? 'Actual return recorded' : ($eventCode === 'vehicle_recovered' ? 'Vehicle recovery recorded' : ucwords(str_replace('_', ' ', $eventCode)) . ' recorded'))),
            'occurred_at_label' => date('M j, Y g:i A', strtotime((string) $facts['occurred_at'])),
            'location_label' => $isHandoff ? 'Handoff location' : ($eventCode === 'vehicle_staged' ? 'Staging location' : ($eventCode === 'actual_return' ? 'Return location' : ($eventCode === 'vehicle_recovered' ? 'Recovery location' : ($eventCode === 'vehicle_positioned' ? 'Position recorded at' : 'Event location')))),
            'location_class_label' => $locationLabels[$locationClass] ?? ucwords(str_replace('_', ' ', $locationClass)),
            'location_detail_value' => $locationDetail === '' ? null : $locationDetail,
            'airport_parking' => $parking,
            'airport_garage_line' => $parkingPresentation['garage_line'] ?? null,
            'airport_position_line' => $parkingPresentation['position_line'] ?? null,
            'airport_location_label' => $parkingPresentation['location_label'] ?? null,
            'approved_turo_garage' => $parkingPresentation['approved_turo_garage'] ?? null,
            'condition_evidence' => $conditionEvidence,
            'cleanliness_label' => $isHandoff ? ($conditionEvidence['cleanliness']['value_label'] ?? 'No prior observation') : ($facts['cleanliness'] === null ? 'Not captured' : ucfirst((string) $facts['cleanliness'])),
            'energy_label' => $energyLabel,
            'energy_value' => $isHandoff ? ($conditionEvidence['energy_percent']['value_label'] ?? 'No prior observation') : ($facts['energy_percent'] === null ? 'Not captured' : (string) $facts['energy_percent'] . '%'),
            'source_label' => $facts['source'] === 'checklist_operator' ? 'Manual' : ucwords(str_replace('_', ' ', (string) $facts['source'])),
            'actor_label' => trim((string) ($facts['actor_username'] ?? '')) ?: 'User #' . (int) $facts['actor_user_id'],
            'form_data' => [
                'event_id' => $facts['event_id'],
                'assessment_id' => $facts['assessment_id'],
                'occurred_at' => date('Y-m-d\TH:i', strtotime((string) $facts['occurred_at'])),
                'location_class' => $facts['location_class'],
                'location_detail' => $facts['location_detail'],
                'airport_garage_code' => $parking['garage_code'] ?? null,
                'airport_parking_level' => $parking['level'] ?? null,
                'airport_parking_row' => $parking['row'] ?? null,
                'cleanliness' => $facts['cleanliness'],
                'energy_percent' => $facts['energy_percent'],
                'note' => $facts['note'],
            ],
        ]);
    }

    /** @return array<string, array<string, mixed>> */
    private function handoffConditionEvidence(array $facts): array
    {
        $prior = $facts['cleanliness'] === null || $facts['energy_percent'] === null
            ? $this->repo()->preHandoffConditionEvidence((int) $facts['event_id']) : [];
        $evidence = [];
        foreach (['cleanliness', 'energy_percent'] as $field) {
            $atHandoff = $facts[$field] !== null;
            $source = $atHandoff ? $facts : ($prior[$field] ?? null);
            if ($source === null) {
                continue;
            }
            $evidence[$field] = [
                'value_label' => $field === 'cleanliness' ? ucfirst((string) $source[$field]) : (string) $source[$field] . '%',
                'origin' => $atHandoff ? 'handoff' : 'staging',
                'event_id' => $source['event_id'],
                'assessment_id' => $source['assessment_id'],
                'occurred_at' => $source['occurred_at'],
                'provenance_label' => ($atHandoff ? 'At handoff at ' : 'From staging at ') . date('M j, Y g:i A', strtotime((string) $source['occurred_at'])) . ' Honolulu',
                'source_label' => $source['source'] === 'checklist_operator' ? 'Manual' : ucwords(str_replace('_', ' ', (string) $source['source'])),
                'actor_label' => trim((string) ($source['actor_username'] ?? '')) ?: 'User #' . (int) $source['actor_user_id'],
            ];
        }

        return $evidence;
    }

    /** @return array<string, mixed> */
    public function mergeCorrectionFormData(array $active, array $submitted): array
    {
        $merged = $active;
        foreach ($submitted as $key => $value) {
            if (in_array($key, ['occurred_at', 'location_class', 'location_detail', 'airport_garage_code', 'airport_parking_level', 'airport_parking_row', 'cleanliness', 'energy_percent', 'note'], true)
                && is_string($value) && trim($value) === '') {
                continue;
            }
            $merged[$key] = $value;
        }

        return $merged;
    }

    /** @return array{garage_code:string,level:int,row:string}|null */
    private function airportParking(array $facts): ?array
    {
        $garage = trim((string) ($facts['airport_garage_code'] ?? ''));
        $level = $facts['airport_parking_level'] ?? null;
        $row = trim((string) ($facts['airport_parking_row'] ?? ''));
        if ($garage !== '' || $level !== null || $row !== '') {
            try {
                return $this->hnlGarages->validate($garage, $level, $row);
            } catch (\InvalidArgumentException) {
                return null;
            }
        }

        return $this->hnlGarages->parseLegacyDetail($facts['location_detail'] ?? null);
    }

    private function repo(): OperationalFactsRepository
    {
        return $this->repository ?? new OperationalFactsRepository();
    }
}
