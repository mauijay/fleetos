<?php

namespace App\Services\Fleet;

class AirportInstructionService
{
    /** @return array{complete: bool, text: string, missing: array<int, string>} */
    public function pickupInstructions(array $workflow): array
    {
        $required = ['garage', 'parking_level', 'parking_stall'];
        $missing = $this->missing($workflow, $required);

        if ($missing !== []) {
            return ['complete' => false, 'text' => '', 'missing' => $missing];
        }

        $location = trim((string) $workflow['garage'] . ' on Level ' . (string) $workflow['parking_level'] . $this->rowStall($workflow));
        $warning = 'When exiting the airport garage, please wait for the license-plate reader to recognize the vehicle and open the gate automatically. Do not press the ticket button or pull a parking ticket; pulling a ticket overrides Turo airport access and creates a paid parking charge.';
        $text = 'Your Tesla is parked in the ' . $location . '. From the terminal, follow signs to the parking garage, cross the pedestrian bridge when needed, and take the elevator to Level ' . (string) $workflow['parking_level'] . '. ' . $warning . ' Message me once you reach the car if you need assistance.';

        return ['complete' => true, 'text' => $text, 'missing' => []];
    }

    /** @return array{complete: bool, text: string, missing: array<int, string>} */
    public function returnInstructions(array $workflow): array
    {
        $garage = (string) ($workflow['garage'] ?? 'HNL International Parking Garage');
        $warning = 'When entering the airport garage, please wait for the license-plate reader to recognize the vehicle and open the gate automatically. Do not press the ticket button or pull a parking ticket; pulling a ticket overrides Turo airport access and creates a paid parking charge.';
        $text = 'Please return the vehicle to ' . $garage . '. ' . $warning . ' Park near the elevators if practical, lock the vehicle, leave the key card as instructed, and send the level, row, stall, and return photos before you leave.';

        return ['complete' => true, 'text' => $text, 'missing' => []];
    }

    /** @return array<int, string> */
    private function missing(array $workflow, array $keys): array
    {
        return array_values(array_filter($keys, static fn (string $key): bool => trim((string) ($workflow[$key] ?? '')) === ''));
    }

    private function rowStall(array $workflow): string
    {
        $parts = [];
        if (trim((string) ($workflow['parking_row'] ?? '')) !== '') {
            $parts[] = 'Row ' . (string) $workflow['parking_row'];
        }
        if (trim((string) ($workflow['parking_stall'] ?? '')) !== '') {
            $parts[] = 'Stall ' . (string) $workflow['parking_stall'];
        }

        return $parts === [] ? '' : ', ' . implode(', ', $parts);
    }
}
