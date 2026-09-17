<?php

namespace App\Repositories;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

class MovementReadinessReadModelRepository
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * @param list<int> $checklistIds
     * @return array<int, array<string, mixed>>
     */
    public function loadForCompany(int $companyId, array $checklistIds, \DateTimeImmutable $asOf): array
    {
        $checklistIds = array_values(array_unique(array_filter(array_map('intval', $checklistIds), static fn (int $id): bool => $id > 0)));
        if ($companyId < 1 || $checklistIds === []) {
            return [];
        }

        $checklists = $this->db->table('trip_movement_checklists checklists')
            ->select('checklists.id, checklists.turo_trip_normalized_id, checklists.fleet_vehicle_id, checklists.movement_type, checklists.scheduled_at, checklists.readiness_status, checklists.vehicle_disposition, checklists.completed_at, checklists.completion_note')
            ->select('trips.starts_at, trips.ends_at, vehicles.company_id')
            ->join('turo_trips_normalized trips', 'trips.id = checklists.turo_trip_normalized_id')
            ->join('fleet_vehicles vehicles', 'vehicles.id = checklists.fleet_vehicle_id AND vehicles.id = trips.fleet_vehicle_id')
            ->where('vehicles.company_id', $companyId)
            ->whereIn('checklists.id', $checklistIds)
            ->orderBy('checklists.id', 'ASC')
            ->get()
            ->getResultArray();
        if ($checklists === []) {
            return [];
        }

        $scopedChecklistIds = array_map('intval', array_column($checklists, 'id'));
        $tripIds = array_values(array_unique(array_map('intval', array_column($checklists, 'turo_trip_normalized_id'))));
        $vehicleIds = array_values(array_unique(array_map('intval', array_column($checklists, 'fleet_vehicle_id'))));
        $nextTripsByVehicle = $this->nextTripCandidates($checklists, $vehicleIds);
        $nextTripByChecklist = [];
        $readinessTripIds = $tripIds;
        foreach ($checklists as $checklist) {
            if (($checklist['movement_type'] ?? null) !== 'return') {
                continue;
            }
            $nextTrip = $this->eligibleNextTrip($checklist, $nextTripsByVehicle[(int) $checklist['fleet_vehicle_id']] ?? []);
            $nextTripByChecklist[(int) $checklist['id']] = $nextTrip;
            if ($nextTrip !== null) {
                $readinessTripIds[] = (int) $nextTrip['id'];
            }
        }
        $readinessTripIds = array_values(array_unique($readinessTripIds));
        $asOfTimestamp = $asOf->format('Y-m-d H:i:s');

        $items = $this->db->table('trip_movement_checklist_items')
            ->whereIn('trip_movement_checklist_id', $scopedChecklistIds)
            ->orderBy('sort_order', 'ASC')
            ->orderBy('id', 'ASC')
            ->get()
            ->getResultArray();
        $events = $this->db->table('trip_movement_events')
            ->where('company_id', $companyId)
            ->whereIn('turo_trip_normalized_id', $readinessTripIds)
            ->where('voided_at', null)
            ->where('occurred_at <=', $asOfTimestamp)
            ->orderBy('occurred_at', 'DESC')
            ->orderBy('id', 'DESC')
            ->get()
            ->getResultArray();
        $assessments = $this->db->table('movement_assessments assessments')
            ->select('assessments.*')
            ->join('trip_movement_events events', 'events.id = assessments.trip_movement_event_id AND events.voided_at IS NULL')
            ->where('assessments.company_id', $companyId)
            ->where('assessments.voided_at', null)
            ->whereIn('assessments.turo_trip_normalized_id', $readinessTripIds)
            ->where('assessments.captured_at <=', $asOfTimestamp)
            ->where('events.occurred_at <=', $asOfTimestamp)
            ->orderBy('assessments.captured_at', 'DESC')
            ->orderBy('assessments.id', 'DESC')
            ->get()
            ->getResultArray();
        $currentReadiness = $this->db->table('movement_assessments assessments')
            ->select('assessments.*, events.occurred_at AS event_occurred_at, events.event_code')
            ->join('trip_movement_events events', 'events.id = assessments.trip_movement_event_id')
            ->where('assessments.company_id', $companyId)
            ->whereIn('assessments.fleet_vehicle_id', $vehicleIds)
            ->where('assessments.turo_trip_normalized_id', null)
            ->where('assessments.movement_type', 'current')
            ->where('assessments.voided_at', null)
            ->where('assessments.captured_at <=', $asOfTimestamp)
            ->where('events.event_code', 'vehicle_readiness_observed')
            ->where('events.voided_at', null)
            ->where('events.occurred_at <=', $asOfTimestamp)
            ->orderBy('assessments.captured_at', 'DESC')
            ->orderBy('assessments.id', 'DESC')
            ->get()
            ->getResultArray();
        $profiles = $this->db->table('vehicle_operational_profiles profiles')
            ->select('profiles.*')
            ->join('fleet_vehicles vehicles', 'vehicles.id = profiles.fleet_vehicle_id')
            ->where('vehicles.company_id', $companyId)
            ->whereIn('profiles.fleet_vehicle_id', $vehicleIds)
            ->get()
            ->getResultArray();
        $capabilities = $this->db->table('vehicle_operational_capabilities capabilities')
            ->select('capabilities.fleet_vehicle_id, capabilities.capability_code')
            ->join('fleet_vehicles vehicles', 'vehicles.id = capabilities.fleet_vehicle_id')
            ->where('vehicles.company_id', $companyId)
            ->where('capabilities.is_applicable', true)
            ->whereIn('capabilities.fleet_vehicle_id', $vehicleIds)
            ->orderBy('capabilities.id', 'ASC')
            ->get()
            ->getResultArray();
        $scheduledLocations = $this->db->table('scheduled_movement_locations')
            ->whereIn('turo_trip_normalized_id', $tripIds)
            ->get()
            ->getResultArray();
        $airportWorkflows = $this->db->table('airport_movement_workflows workflows')
            ->select('workflows.*')
            ->join('fleet_vehicles vehicles', 'vehicles.id = workflows.fleet_vehicle_id')
            ->where('vehicles.company_id', $companyId)
            ->whereIn('workflows.turo_trip_normalized_id', $tripIds)
            ->orderBy('workflows.scheduled_at', 'DESC')
            ->orderBy('workflows.id', 'DESC')
            ->get()
            ->getResultArray();
        $positioningPlans = $this->db->table('vehicle_positioning_plans')
            ->where('company_id', $companyId)
            ->whereIn('fleet_vehicle_id', $vehicleIds)
            ->where('invalidated_at', null)
            ->groupStart()
                ->where('expires_at', null)
                ->orWhere('expires_at >', $asOf->format('Y-m-d H:i:s'))
            ->groupEnd()
            ->orderBy('created_at', 'DESC')
            ->orderBy('id', 'DESC')
            ->get()
            ->getResultArray();

        $itemsByChecklist = [];
        foreach ($items as $item) {
            $itemsByChecklist[(int) $item['trip_movement_checklist_id']][(string) $item['item_code']] = $item;
        }
        $eventsByTrip = [];
        foreach ($events as $event) {
            $tripId = (int) $event['turo_trip_normalized_id'];
            $movementType = (string) ($event['movement_type'] ?? '');
            $eventCode = (string) $event['event_code'];
            $eventsByTrip[$tripId][$movementType][$eventCode] ??= $event;
        }
        $assessmentsByTrip = [];
        foreach ($assessments as $assessment) {
            $tripId = (int) $assessment['turo_trip_normalized_id'];
            $movementType = (string) $assessment['movement_type'];
            $assessmentsByTrip[$tripId][$movementType] ??= $assessment;
        }
        $currentReadinessByVehicle = [];
        foreach ($currentReadiness as $assessment) {
            $currentReadinessByVehicle[(int) $assessment['fleet_vehicle_id']] ??= $assessment;
        }
        $profilesByVehicle = [];
        foreach ($profiles as $profile) {
            $profilesByVehicle[(int) $profile['fleet_vehicle_id']] = $profile;
        }
        $capabilitiesByVehicle = [];
        foreach ($capabilities as $capability) {
            $capabilitiesByVehicle[(int) $capability['fleet_vehicle_id']][] = (string) $capability['capability_code'];
        }
        $locationsByTrip = [];
        foreach ($scheduledLocations as $location) {
            $locationsByTrip[(int) $location['turo_trip_normalized_id']][(string) $location['movement_type']] = $location;
        }
        $airportByTrip = [];
        foreach ($airportWorkflows as $workflow) {
            $tripId = (int) $workflow['turo_trip_normalized_id'];
            $movementType = (string) $workflow['movement_type'];
            $airportByTrip[$tripId][$movementType] ??= $workflow;
        }
        $plansByVehicle = [];
        foreach ($positioningPlans as $plan) {
            $plansByVehicle[(int) $plan['fleet_vehicle_id']] ??= $plan;
        }

        $contexts = [];
        foreach ($checklists as $checklist) {
            $checklistId = (int) $checklist['id'];
            $tripId = (int) $checklist['turo_trip_normalized_id'];
            $vehicleId = (int) $checklist['fleet_vehicle_id'];
            $movementType = (string) $checklist['movement_type'];
            $nextTrip = $nextTripByChecklist[$checklistId] ?? null;
            $contexts[$checklistId] = array_merge($checklist, [
                'items_by_code' => $itemsByChecklist[$checklistId] ?? [],
                'active_events' => $eventsByTrip[$tripId][$movementType] ?? [],
                'active_assessment' => $assessmentsByTrip[$tripId][$movementType] ?? null,
                'current_readiness_assessment' => $currentReadinessByVehicle[$vehicleId] ?? null,
                'target_pickup_assessment' => $nextTrip === null ? null : ($assessmentsByTrip[(int) $nextTrip['id']]['pickup'] ?? null),
                'profile' => $profilesByVehicle[$vehicleId] ?? null,
                'capabilities' => $capabilitiesByVehicle[$vehicleId] ?? [],
                'scheduled_location' => $locationsByTrip[$tripId][$movementType] ?? null,
                'airport_workflow' => $airportByTrip[$tripId][$movementType] ?? null,
                'positioning_plan' => $plansByVehicle[$vehicleId] ?? null,
                'next_trip' => $nextTrip,
                'next_pickup_handoff' => $nextTrip === null ? null : ($eventsByTrip[(int) $nextTrip['id']]['pickup']['actual_handoff'] ?? null),
            ]);
        }

        return $contexts;
    }

    /** @param array<int, array<string, mixed>> $checklists @param list<int> $vehicleIds @return array<int, list<array<string, mixed>>> */
    private function nextTripCandidates(array $checklists, array $vehicleIds): array
    {
        if (! array_any($checklists, static fn (array $checklist): bool => $checklist['movement_type'] === 'return')) {
            return [];
        }

        $builder = $this->db->table('turo_trips_normalized trips')
            ->select('trips.id, trips.fleet_vehicle_id, trips.starts_at, trips.ends_at, trips.canceled_at, trip_statuses.code AS trip_status_code')
            ->join('lookup_values trip_statuses', 'trip_statuses.id = trips.trip_status_lookup_value_id', 'left')
            ->whereIn('trips.fleet_vehicle_id', $vehicleIds)
            ->where('trips.deleted_at', null)
            ->orderBy('trips.starts_at', 'ASC')
            ->orderBy('trips.id', 'ASC');

        $byVehicle = [];
        foreach ($builder->get()->getResultArray() as $trip) {
            $byVehicle[(int) $trip['fleet_vehicle_id']][] = $trip;
        }

        return $byVehicle;
    }

    /** @param array<string, mixed> $checklist @param list<array<string, mixed>> $candidates @return array<string, mixed>|null */
    private function eligibleNextTrip(array $checklist, array $candidates): ?array
    {
        try {
            $returnAt = new \DateTimeImmutable((string) $checklist['ends_at']);
        } catch (\Exception) {
            return null;
        }

        foreach ($candidates as $candidate) {
            $status = (string) ($candidate['trip_status_code'] ?? '');
            if ((int) $candidate['id'] === (int) $checklist['turo_trip_normalized_id']
                || ($candidate['canceled_at'] ?? null) !== null
                || str_starts_with($status, 'canceled')
                || $status === 'invalid') {
                continue;
            }
            try {
                $startsAt = new \DateTimeImmutable((string) $candidate['starts_at']);
                $endsAt = new \DateTimeImmutable((string) $candidate['ends_at']);
            } catch (\Exception) {
                continue;
            }
            if ($endsAt <= $startsAt || $startsAt < $returnAt) {
                continue;
            }

            return array_merge($candidate, [
                'is_same_day_turnaround' => $returnAt->format('Y-m-d') === $startsAt->format('Y-m-d'),
            ]);
        }

        return null;
    }
}
