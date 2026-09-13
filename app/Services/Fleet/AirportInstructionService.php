<?php

namespace App\Services\Fleet;

class AirportInstructionService
{
    /** @return array{complete: bool, text: string, missing: array<int, string>} */
    public function pickupInstructions(array $workflow): array
    {
        $required = ['garage', 'parking_level', 'parking_row'];
        $missing = $this->missing($workflow, $required);

        if ($missing !== []) {
            return ['complete' => false, 'text' => '', 'missing' => $missing];
        }

        $location = trim((string) $workflow['garage'] . ' on Level ' . (string) $workflow['parking_level'] . $this->row($workflow));
        $warning = 'When exiting the airport garage, wait for the license-plate reader to recognize the vehicle and open the gate automatically. If the gate does not open, contact me for help before paying.';
        $text = 'Your Tesla is parked in the ' . $location . '. From the terminal, follow signs to the parking garage, cross the pedestrian bridge when needed, and take the elevator to Level ' . (string) $workflow['parking_level'] . '. ' . $warning . ' Message me once you reach the car if you need assistance.';

        return ['complete' => true, 'text' => $text, 'missing' => []];
    }

    /** @return array{complete: bool, text: string, missing: array<int, string>} */
    public function returnInstructions(array $workflow): array
    {
        $garage = (string) ($workflow['garage'] ?? 'HNL International Parking Garage');
        $warning = 'When entering the airport garage, wait for the license-plate reader to open the gate automatically. If it does not open, pull a parking ticket and leave the ticket visible in the vehicle for pickup.';
        $text = 'Please return the vehicle to ' . $garage . '. ' . $warning . ' Park near the elevators if practical, lock the vehicle, leave the key card as instructed, and send the level, row, and return photos before you leave.';

        return ['complete' => true, 'text' => $text, 'missing' => []];
    }

    /** @return array<int, string> */
    private function missing(array $workflow, array $keys): array
    {
        return array_values(array_filter($keys, static fn (string $key): bool => trim((string) ($workflow[$key] ?? '')) === ''));
    }

    private function row(array $workflow): string
    {
        $parts = [];
        if (trim((string) ($workflow['parking_row'] ?? '')) !== '') {
            $parts[] = 'Row ' . (string) $workflow['parking_row'];
        }

        return $parts === [] ? '' : ', ' . implode(', ', $parts);
    }
}
