<?php

namespace App\Services\Fleet;

class MovementChecklistDefinitionService
{
    /** @return array<int, array<string, mixed>> */
    public function items(string $movementType, bool $isAirport = false): array
    {
        $items = $movementType === 'return' ? $this->returnItems() : $this->pickupItems();

        if ($movementType === 'pickup' && $isAirport) {
            $items[] = $this->item('airport_staging_completed', 'Airport staging completed', true, false, 90);
            $items[] = $this->item('parking_location_recorded', 'Parking location recorded', true, false, 100);
            $items[] = $this->item('guest_pickup_instructions_confirmed', 'Guest pickup instructions confirmed', true, false, 110);
            $items[] = $this->item('turo_access_instructions_confirmed', 'Turo Access instructions confirmed', true, false, 115);
        }

        return $items;
    }

    /** @return array<int, array<string, mixed>> */
    private function pickupItems(): array
    {
        return [
            $this->item('vehicle_inspected', 'Vehicle inspected', true, true, 10),
            $this->item('vehicle_cleaned', 'Vehicle cleaned', true, true, 20),
            $this->item('charge_confirmed', 'Charge confirmed', true, true, 30),
            $this->item('exterior_photos_completed', 'Exterior condition photos completed', true, true, 40),
            $this->item('interior_photos_completed', 'Interior condition photos completed', true, true, 50),
            $this->item('key_card_confirmed', 'Key card confirmed', true, true, 60),
            $this->item('location_confirmed', 'Pickup or delivery location confirmed', true, false, 70),
            $this->item('vehicle_staged', 'Vehicle staged or ready', true, true, 80),
            $this->item('guest_handoff_completed', 'Guest handoff completed', true, false, 120),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function returnItems(): array
    {
        return [
            $this->item('vehicle_received', 'Return received or vehicle located', true, true, 10),
            $this->item('return_time_confirmed', 'Return time confirmed', true, false, 20),
            $this->item('exterior_inspected', 'Exterior inspected', true, true, 30),
            $this->item('interior_inspected', 'Interior inspected', true, true, 40),
            $this->item('damage_check_completed', 'Damage check completed', true, true, 50),
            $this->item('charge_confirmed', 'Charge confirmed', true, true, 60),
            $this->item('return_photos_completed', 'Return photos completed', true, false, 70),
            $this->item('cleaning_status_assigned', 'Cleaning status assigned', true, false, 80),
            $this->item('vehicle_disposition_selected', 'Vehicle disposition assigned', true, true, 90),
            $this->item('return_workflow_completed', 'Return workflow completed', true, false, 100),
        ];
    }

    /** @return array<string, mixed> */
    private function item(string $code, string $label, bool $required, bool $critical, int $sortOrder): array
    {
        return compact('code', 'label', 'required', 'critical', 'sortOrder');
    }
}
