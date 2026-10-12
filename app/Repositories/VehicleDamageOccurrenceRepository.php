<?php

namespace App\Repositories;

use App\Database\VehicleDamageOccurrenceProvenanceSchema as Schema;
use App\Services\Fleet\VehicleDamageOccurrenceBasisSnapshot;
use CodeIgniter\Database\BaseConnection;
use InvalidArgumentException;

/** Parent-scoped provenance primitives; no wheel episode policy or implicit writes. */
final class VehicleDamageOccurrenceRepository
{
    public const EVENTS = ['new_damage' => 'created', 'worsened' => 'worsened', 'observed_existing' => 'observed_existing'];

    public function __construct(private readonly BaseConnection $db)
    {
    }

    public function ready(): bool
    {
        return Schema::ready($this->db) && $this->recorded();
    }

    public function requireReady(): void
    {
        if (! $this->ready()) {
            throw new \RuntimeException('Occurrence provenance schema or migration ledger is incomplete. Inspect migration 000036 before enabling provenance writes.');
        }
    }

    /** Absence is legacy-compatible; a partial footprint never silently downgrades. */
    public function available(): bool
    {
        foreach ([['vehicle_damage_incident_items', 'condition_event_id'], ['vehicle_damage_incident_items', 'basis_snapshot'], ['vehicle_damage_item_evidence', 'vehicle_damage_incident_item_id']] as [$table, $field]) {
            if ($this->db->tableExists($table) && $this->db->fieldExists($field, $table)) {
                $this->requireReady();
                return true;
            }
        }
        foreach (Schema::INDEXES as $name => [$table]) {
            if ($this->db->tableExists($table) && isset($this->db->getIndexData($table)[$name])) {
                $this->requireReady();
                return true;
            }
        }
        if ($this->recorded() || ! Schema::matchesStage($this->db, 0)) {
            $this->requireReady();
        }
        return false;
    }

    /** @return array<string,mixed>|null */
    public function event(int $company, int $vehicle, int $condition, int $event): ?array
    {
        return $this->db->table('vehicle_damage_item_events events')->select('events.*')
            ->join('vehicle_damage_items items', 'items.id=events.vehicle_damage_item_id AND items.company_id=events.company_id')
            ->join('fleet_vehicles vehicles', 'vehicles.id=items.fleet_vehicle_id AND vehicles.company_id=items.company_id AND vehicles.deleted_at IS NULL')
            ->where('events.company_id', $company)->where('items.fleet_vehicle_id', $vehicle)->where('items.id', $condition)->where('events.id', $event)->get()->getRowArray();
    }

    /** @return array<string,mixed>|null */
    public function membership(int $company, int $vehicle, int $membership): ?array
    {
        return $this->db->table('vehicle_damage_incident_items members')->select('members.*')
            ->join('vehicle_damage_incidents incidents', 'incidents.id=members.vehicle_damage_incident_id AND incidents.company_id=members.company_id')
            ->join('vehicle_damage_items items', 'items.id=members.vehicle_damage_item_id AND items.company_id=members.company_id AND items.fleet_vehicle_id=incidents.fleet_vehicle_id')
            ->join('fleet_vehicles vehicles', 'vehicles.id=items.fleet_vehicle_id AND vehicles.company_id=items.company_id AND vehicles.deleted_at IS NULL')
            ->select('incidents.occurred_at AS incident_occurred_at, incidents.discovered_at AS incident_discovered_at')
            ->where('members.company_id', $company)->where('items.fleet_vehicle_id', $vehicle)->where('members.id', $membership)->get()->getRowArray();
    }

    /** @param array<string,mixed> $values */
    public function validateMembership(array $values): void
    {
        $this->requireReady();
        $company = VehicleDamageOccurrenceBasisSnapshot::positiveId($values['company_id'] ?? null);
        $incidentId = VehicleDamageOccurrenceBasisSnapshot::positiveId($values['vehicle_damage_incident_id'] ?? null);
        $conditionId = VehicleDamageOccurrenceBasisSnapshot::positiveId($values['vehicle_damage_item_id'] ?? null);
        if (! is_string($values['effect_code'] ?? null) || ! isset(self::EVENTS[$values['effect_code']])) {
            throw new InvalidArgumentException('Choose a supported occurrence effect.');
        }
        $incident = $this->db->table('vehicle_damage_incidents incidents')->select('incidents.*')
            ->join('fleet_vehicles vehicles', 'vehicles.id=incidents.fleet_vehicle_id AND vehicles.company_id=incidents.company_id AND vehicles.deleted_at IS NULL')
            ->where('incidents.company_id', $company)->where('incidents.id', $incidentId)->get()->getRowArray();
        if ($incident === null || (new VehicleDamageRepository($this->db))->item($company, (int) $incident['fleet_vehicle_id'], $conditionId) === null) {
            throw new InvalidArgumentException('Occurrence membership parents must belong to the same company and vehicle.');
        }
        $vehicle = (int) $incident['fleet_vehicle_id'];
        if (($values['condition_event_id'] ?? null) !== null) {
            $eventId = VehicleDamageOccurrenceBasisSnapshot::positiveId($values['condition_event_id']);
            $event = $this->event($company, $vehicle, (int) $values['vehicle_damage_item_id'], $eventId);
            if ($event === null || ($event['event_code'] ?? '') !== self::EVENTS[$values['effect_code']]) {
                throw new InvalidArgumentException('Occurrence effect and owned condition event must agree.');
            }
            $item = (new VehicleDamageRepository($this->db))->item($company, $vehicle, (int) $values['vehicle_damage_item_id']);
            if (($values['panel_code'] ?? null) !== ($item['panel_code'] ?? null) || $values['damage_type_code'] !== $item['damage_type_code'] || $values['severity_code'] !== $event['new_severity_code']) {
                throw new InvalidArgumentException('Occurrence panel, type and event severity must agree.');
            }
        }
        if (($values['basis_snapshot'] ?? null) !== null) {
            if (! is_string($values['basis_snapshot']) || strlen($values['basis_snapshot']) > VehicleDamageOccurrenceBasisSnapshot::MAX_BYTES) {
                throw new InvalidArgumentException('Occurrence basis must be bounded JSON text.');
            }
            $snapshot = json_decode($values['basis_snapshot'], true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($snapshot)) {
                throw new InvalidArgumentException('Occurrence basis must be an object.');
            }
            (new VehicleDamageOccurrenceBasisSnapshot($this->db))->validateForWrite($company, $vehicle, $snapshot);
            if ($snapshot['panel_code'] !== ($values['panel_code'] ?? null) || $snapshot['damage_type_code'] !== $values['damage_type_code']) {
                throw new InvalidArgumentException('Occurrence basis must match membership identity.');
            }
            $prior = $snapshot['prior_condition'];
            if (($values['condition_event_id'] ?? null) === null || ($values['effect_code'] === 'new_damage' && $prior !== null)
                || ($values['effect_code'] !== 'new_damage' && $prior === null)
                || ($prior !== null && ($prior['canonical_condition_item_id'] !== (int) $values['vehicle_damage_item_id'] || $prior['latest_condition_event_id'] >= (int) $values['condition_event_id']))
                || $snapshot['snapshot_recorded_at'] > $values['created_at']) {
                throw new InvalidArgumentException('Occurrence basis must precede its exact affected condition event.');
            }
        }
    }

    /** Validate original evidence parent; aliases authorize exactly one existing owned hop. */
    public function validateEvidenceLink(int $company, int $vehicle, int $originalCondition, int $membership): void
    {
        $this->requireReady();
        $member = $this->membership($company, $vehicle, $membership);
        $items = new VehicleDamageRepository($this->db);
        $original = $items->item($company, $vehicle, $originalCondition);
        if ($original === null || $member === null) {
            throw new InvalidArgumentException('Evidence and occurrence must have owned parents.');
        }
        $canonical = $items->canonicalItem($company, $vehicle, $originalCondition);
        if ((int) $member['vehicle_damage_item_id'] !== $originalCondition && (int) $member['vehicle_damage_item_id'] !== (int) ($canonical['id'] ?? 0)) {
            throw new InvalidArgumentException('Evidence occurrence must belong to its original or one-hop canonical condition.');
        }
    }

    /** Existing ownership/private metadata checks remain authoritative. @return list<array<string,mixed>> */
    public function evidence(int $company, int $vehicle, int $membership): array
    {
        $this->requireReady();
        $member = $this->membership($company, $vehicle, $membership);
        if ($member === null) {
            return [];
        }
        $parents = $this->db->table('vehicle_damage_item_evidence evidence')->select('evidence.vehicle_damage_item_id')->distinct()
            ->join('vehicle_damage_items items', 'items.id=evidence.vehicle_damage_item_id AND items.company_id=evidence.company_id')
            ->where('evidence.company_id', $company)->where('items.fleet_vehicle_id', $vehicle)->where('evidence.vehicle_damage_incident_item_id', $membership)->get()->getResultArray();
        $rows = [];
        foreach ($parents as $parent) {
            $condition = (int) $parent['vehicle_damage_item_id'];
            $this->validateEvidenceLink($company, $vehicle, $condition, $membership);
            foreach ((new VehicleDamageRepository($this->db))->evidence($company, $condition) as $row) {
                if ((int) ($row['vehicle_damage_incident_item_id'] ?? 0) === $membership) {
                    $rows[] = $row;
                }
            }
        }
        return $rows;
    }

    private function recorded(): bool
    {
        return $this->db->tableExists('migrations') && $this->db->table('migrations')
            ->where('version', '2026-10-11-000036')->where('namespace', 'App')
            ->where('class', 'App\\Database\\Migrations\\AddVehicleDamageOccurrenceProvenance')->countAllResults() === 1;
    }
}
