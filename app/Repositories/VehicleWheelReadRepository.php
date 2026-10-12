<?php

namespace App\Repositories;

use App\Services\Fleet\VehicleWheelIdentity;
use CodeIgniter\Database\BaseConnection;
use RuntimeException;

/** Owned, bounded reads only. SQL is shared by acceptance EXPLAIN and production read paths. */
final class VehicleWheelReadRepository
{
    public function __construct(private readonly BaseConnection $db)
    {
    }

    private function t(string $name): string
    {
        return $this->db->escapeIdentifiers($this->db->prefixTable($name));
    }

    private function wheelPanels(): string
    {
        return implode(',', array_map($this->db->escape(...), array_column(VehicleWheelIdentity::choices(), 'panel_code')));
    }

    public function rows(string $sql): array
    {
        $result = $this->db->query($sql);
        if ($result === false) {
            throw new RuntimeException('Wheel history is unavailable.');
        }
        return $result->getResultArray();
    }

    /** @param list<int> $vehicles @param array<int,int> $contexts @return list<string> */
    public function projectionQueries(int $company, array $vehicles, array $contexts): array
    {
        $ids = implode(',', array_map('intval', $vehicles));
        $v = $this->t('fleet_vehicles');
        $d = $this->t('vehicle_damage_items');
        $e = $this->t('vehicle_damage_item_events');
        $m = $this->t('vehicle_damage_incident_items');
        $i = $this->t('vehicle_damage_incidents');
        $trips = $this->t('turo_trips_normalized');
        $jobs = $this->t('vehicle_damage_repair_jobs');
        $work = $this->t('vehicle_damage_repair_job_items');
        $context = '0';
        if ($contexts !== []) {
            $context = 'CASE v.id';
            foreach ($contexts as $vehicle => $trip) {
                $context .= ' WHEN ' . (int) $vehicle . ' THEN ' . (int) $trip;
            }
            $context .= ' ELSE 0 END';
        }
        $owned = "v.company_id={$company} AND v.id IN ({$ids}) AND v.deleted_at IS NULL";
        $target = "LEFT JOIN {$d} target ON target.id=d.current_condition_item_id AND target.company_id=d.company_id AND target.fleet_vehicle_id=d.fleet_vehicle_id";
        $tripJoin = "LEFT JOIN {$trips} context_trip ON context_trip.id=({$context}) AND context_trip.fleet_vehicle_id=v.id AND context_trip.deleted_at IS NULL";
        $q1 = "SELECT v.id AS owned_vehicle_id, context_trip.id AS context_trip_id, d.*,
            target.id AS target_id,target.company_id AS target_company,target.fleet_vehicle_id AS target_vehicle,target.panel_code AS target_panel,target.damage_type_code AS target_type,target.zone_code AS target_zone,target.current_condition_item_id AS target_alias,
            ev.id AS latest_event_id,ev.event_code AS latest_event_code,ev.occurred_at AS latest_event_occurred_at,ev.created_at AS latest_event_recorded_at,
            EXISTS(SELECT 1 FROM {$d} incoming WHERE incoming.current_condition_item_id=d.id AND (incoming.company_id<>d.company_id OR incoming.fleet_vehicle_id<>d.fleet_vehicle_id)) AS foreign_incoming
            FROM {$v} v LEFT JOIN {$d} d ON d.fleet_vehicle_id=v.id AND d.company_id=v.company_id
            {$target} {$tripJoin}
            LEFT JOIN {$e} ev ON ev.company_id=d.company_id AND ev.vehicle_damage_item_id=d.id AND ev.id=(SELECT MAX(le.id) FROM {$e} le WHERE le.company_id=d.company_id AND le.vehicle_damage_item_id=d.id)
            WHERE {$owned} AND (d.id IS NULL OR d.damage_type_code='wheel_curb_rash' OR d.zone_code='wheel_tire' OR target.damage_type_code='wheel_curb_rash') ORDER BY v.id,d.id";
        // An empty owned vehicle still needs four entries even when it has only non-wheel damage.
        $q1 = str_replace("LEFT JOIN {$d} d ON d.fleet_vehicle_id=v.id AND d.company_id=v.company_id", "LEFT JOIN {$d} d ON d.fleet_vehicle_id=v.id AND d.company_id=v.company_id AND (d.damage_type_code='wheel_curb_rash' OR d.zone_code='wheel_tire' OR EXISTS(SELECT 1 FROM {$d} parent WHERE parent.id=d.current_condition_item_id AND parent.company_id=d.company_id AND parent.fleet_vehicle_id=d.fleet_vehicle_id AND parent.damage_type_code='wheel_curb_rash'))", $q1);
        $valid = '(d.current_condition_item_id IS NULL OR (target.id IS NOT NULL AND target.current_condition_item_id IS NULL AND target.id<>d.id AND target.panel_code=d.panel_code AND target.damage_type_code=d.damage_type_code AND target.zone_code=d.zone_code))';
        $canonical = 'COALESCE(target.id,d.id)';
        $q2 = "SELECT ranked.* FROM (SELECT m.*,d.fleet_vehicle_id,{$canonical} AS canonical_id,
            i.occurred_at AS incident_occurred_at,i.discovered_at AS incident_discovered_at,i.turo_trip_normalized_id AS trip_id,i.attribution_type,i.overall_note AS attribution_reason,
            trip.turo_reservation_id, ev.event_code,ev.occurred_at AS event_occurred_at,ev.created_at AS event_recorded_at,
            ROW_NUMBER() OVER(PARTITION BY {$canonical},m.effect_code ORDER BY COALESCE(i.occurred_at,i.discovered_at) DESC,m.created_at DESC,m.id DESC) AS effect_rank,
            ROW_NUMBER() OVER(PARTITION BY {$canonical},m.effect_code,(i.turo_trip_normalized_id=context_trip.id) ORDER BY m.id DESC) AS trip_rank,
            CASE WHEN i.turo_trip_normalized_id=context_trip.id AND trip.id IS NOT NULL THEN 1 ELSE 0 END AS matches_trip
            FROM {$m} m JOIN {$d} d ON d.id=m.vehicle_damage_item_id AND d.company_id=m.company_id
            JOIN {$v} v ON v.id=d.fleet_vehicle_id AND v.company_id=d.company_id
            JOIN {$i} i ON i.id=m.vehicle_damage_incident_id AND i.company_id=m.company_id AND i.fleet_vehicle_id=v.id
            {$target} {$tripJoin}
            LEFT JOIN {$trips} trip ON trip.id=i.turo_trip_normalized_id AND trip.fleet_vehicle_id=v.id AND trip.deleted_at IS NULL
            JOIN {$e} ev ON ev.id=m.condition_event_id AND ev.company_id=m.company_id AND ev.vehicle_damage_item_id=d.id
                AND ((m.effect_code='new_damage' AND ev.event_code='created') OR (m.effect_code='worsened' AND ev.event_code='worsened') OR (m.effect_code='observed_existing' AND ev.event_code='observed_existing'))
            WHERE {$owned} AND {$valid} AND m.damage_type_code='wheel_curb_rash' AND d.damage_type_code='wheel_curb_rash' AND d.zone_code='wheel_tire'
            ) ranked WHERE effect_rank=1 OR (trip_rank=1 AND matches_trip=1)";
        $q3 = "SELECT ranked.* FROM (SELECT d.id AS condition_id,d.fleet_vehicle_id,
            member.id AS work_membership_id,member.result_code,member.withdrawn_at,jobs.id AS job_id,jobs.status_code AS job_status,member.occurred_at AS work_occurred_at,
            physical.id AS physical_event_id,physical.event_code AS physical_event_code,physical.occurred_at AS physical_occurred_at,physical.created_at AS physical_recorded_at,
            ROW_NUMBER() OVER(PARTITION BY d.id ORDER BY member.id DESC) AS work_rank
            FROM {$d} d JOIN {$v} v ON v.id=d.fleet_vehicle_id AND v.company_id=d.company_id
            LEFT JOIN {$work} member ON member.vehicle_damage_item_id=d.id AND member.company_id=d.company_id AND EXISTS(SELECT 1 FROM {$jobs} owned_job WHERE owned_job.id=member.vehicle_damage_repair_job_id AND owned_job.company_id=d.company_id AND owned_job.fleet_vehicle_id=d.fleet_vehicle_id)
            LEFT JOIN {$jobs} jobs ON jobs.id=member.vehicle_damage_repair_job_id AND jobs.company_id=member.company_id AND jobs.fleet_vehicle_id=d.fleet_vehicle_id
            LEFT JOIN {$e} physical ON physical.company_id=d.company_id AND physical.vehicle_damage_item_id=d.id AND physical.id=(SELECT MAX(pe.id) FROM {$e} pe WHERE pe.company_id=d.company_id AND pe.vehicle_damage_item_id=d.id AND pe.event_code IN ('repaired','repair_reopened','resolved_other'))
            WHERE {$owned} AND d.current_condition_item_id IS NULL AND (d.damage_type_code='wheel_curb_rash' OR d.zone_code='wheel_tire')
            ) ranked WHERE work_rank=1";
        return [$q1,$q2,$q3];
    }

    public function ownedVehicles(int $company, array $vehicles): array
    {
        return $this->rows('SELECT id AS owned_vehicle_id FROM ' . $this->t('fleet_vehicles') . ' WHERE company_id=' . $company . ' AND deleted_at IS NULL AND id IN (' . implode(',', $vehicles) . ')');
    }

    /** Episode numbers are presentation only, in discovery order. */
    public function episodes(int $company, int $vehicle, ?string $panel, int $page): array
    {
        $panels = $this->wheelPanels();
        $where = $panel === null ? "d.zone_code='wheel_tire' AND (d.panel_code IS NULL OR d.panel_code NOT IN ({$panels}))" : 'd.damage_type_code=\'wheel_curb_rash\' AND d.panel_code=' . $this->db->escape($panel);
        $offset = ($page - 1) * 25;
        return $this->rows("SELECT numbered.* FROM (SELECT d.*,ROW_NUMBER() OVER(ORDER BY d.discovered_at,d.id) AS episode_number FROM {$this->t('vehicle_damage_items')} d JOIN {$this->t('fleet_vehicles')} v ON v.id=d.fleet_vehicle_id AND v.company_id=d.company_id WHERE v.company_id={$company} AND v.id={$vehicle} AND v.deleted_at IS NULL AND d.current_condition_item_id IS NULL AND {$where}) numbered ORDER BY episode_number LIMIT 26 OFFSET {$offset}");
    }

    public function condition(int $company, int $vehicle, int $condition): ?array
    {
        $rows = $this->rows("SELECT d.*,target.id AS target_id,target.panel_code AS target_panel,target.damage_type_code AS target_type,target.zone_code AS target_zone,target.current_condition_item_id AS target_alias FROM {$this->t('vehicle_damage_items')} d JOIN {$this->t('fleet_vehicles')} v ON v.id=d.fleet_vehicle_id AND v.company_id=d.company_id LEFT JOIN {$this->t('vehicle_damage_items')} target ON target.id=d.current_condition_item_id AND target.company_id=d.company_id AND target.fleet_vehicle_id=d.fleet_vehicle_id WHERE v.company_id={$company} AND v.id={$vehicle} AND v.deleted_at IS NULL AND d.id={$condition}");
        return $rows[0] ?? null;
    }

    private function episodeScope(int $condition): string
    {
        return "(d.id={$condition} OR (d.current_condition_item_id={$condition} AND target.id={$condition} AND target.current_condition_item_id IS NULL AND target.id<>d.id AND target.panel_code=d.panel_code AND target.damage_type_code=d.damage_type_code AND target.zone_code=d.zone_code))";
    }

    public function timeline(int $company, int $vehicle, int $condition, int $page): array
    {
        $d = $this->t('vehicle_damage_items');
        $v = $this->t('fleet_vehicles');
        $e = $this->t('vehicle_damage_item_events');
        $m = $this->t('vehicle_damage_incident_items');
        $i = $this->t('vehicle_damage_incidents');
        $a = $this->t('audit_logs');
        $base = "FROM {$d} d JOIN {$v} v ON v.id=d.fleet_vehicle_id AND v.company_id=d.company_id LEFT JOIN {$d} target ON target.id=d.current_condition_item_id AND target.company_id=d.company_id AND target.fleet_vehicle_id=d.fleet_vehicle_id";
        $scope = "v.company_id={$company} AND v.id={$vehicle} AND v.deleted_at IS NULL AND {$this->episodeScope($condition)}";
        $events = "SELECT ev.id AS row_id,'condition' AS kind,ev.event_code AS effect,ev.id AS condition_event_id,d.id AS original_condition_id,ev.occurred_at,ev.created_at AS recorded_at,ev.note,ev.new_description,ev.new_severity_code AS severity_code,m.id AS membership_id,i.occurred_at AS damage_occurred_at,i.discovered_at,i.turo_trip_normalized_id AS trip_id,i.attribution_type,m.panel_code AS recorded_panel,m.damage_type_code AS recorded_damage_type, NULL AS before_json,NULL AS after_json {$base} JOIN {$e} ev ON ev.vehicle_damage_item_id=d.id AND ev.company_id=d.company_id LEFT JOIN {$m} m ON m.condition_event_id=ev.id AND m.company_id=ev.company_id AND m.vehicle_damage_item_id=d.id LEFT JOIN {$i} i ON i.id=m.vehicle_damage_incident_id AND i.company_id=m.company_id AND i.fleet_vehicle_id=d.fleet_vehicle_id WHERE {$scope}";
        $legacy = "SELECT m.id AS row_id,'legacy' AS kind,m.effect_code AS effect,NULL AS condition_event_id,d.id AS original_condition_id,i.occurred_at,m.created_at AS recorded_at,m.note,NULL AS new_description,m.severity_code,m.id AS membership_id,i.occurred_at AS damage_occurred_at,i.discovered_at,i.turo_trip_normalized_id AS trip_id,i.attribution_type,m.panel_code AS recorded_panel,m.damage_type_code AS recorded_damage_type,NULL AS before_json,NULL AS after_json {$base} JOIN {$m} m ON m.vehicle_damage_item_id=d.id AND m.company_id=d.company_id JOIN {$i} i ON i.id=m.vehicle_damage_incident_id AND i.company_id=m.company_id AND i.fleet_vehicle_id=d.fleet_vehicle_id WHERE {$scope} AND m.condition_event_id IS NULL";
        $audit = "SELECT DISTINCT audit.id AS row_id,'attribution' AS kind,'attribution_corrected' AS effect,NULL AS condition_event_id,d.id AS original_condition_id,NULL AS occurred_at,audit.created_at AS recorded_at,NULL AS note,NULL AS new_description,NULL AS severity_code,NULL AS membership_id,NULL AS damage_occurred_at,NULL AS discovered_at,NULL AS trip_id,NULL AS attribution_type,NULL AS recorded_panel,NULL AS recorded_damage_type,audit.old_values AS before_json,audit.new_values AS after_json {$base} JOIN {$m} m ON m.vehicle_damage_item_id=d.id AND m.company_id=d.company_id JOIN {$i} i ON i.id=m.vehicle_damage_incident_id AND i.company_id=m.company_id AND i.fleet_vehicle_id=d.fleet_vehicle_id JOIN {$a} audit ON audit.table_name='vehicle_damage_incidents' AND audit.record_id=i.id WHERE {$scope} AND audit.old_values IS NOT NULL";
        return $this->rows("SELECT timeline.* FROM ({$events} UNION ALL {$legacy} UNION ALL {$audit}) timeline ORDER BY COALESCE(occurred_at,recorded_at),recorded_at,row_id,kind LIMIT 26 OFFSET " . (($page - 1) * 25));
    }

    public function repairHistory(int $company, int $vehicle, int $condition, int $page): array
    {
        return $this->rows("SELECT member.*,jobs.status_code AS job_status,jobs.summary FROM {$this->t('vehicle_damage_repair_job_items')} member JOIN {$this->t('vehicle_damage_repair_jobs')} jobs ON jobs.id=member.vehicle_damage_repair_job_id AND jobs.company_id=member.company_id JOIN {$this->t('vehicle_damage_items')} d ON d.id=member.vehicle_damage_item_id AND d.company_id=member.company_id AND d.fleet_vehicle_id=jobs.fleet_vehicle_id JOIN {$this->t('fleet_vehicles')} v ON v.id=d.fleet_vehicle_id AND v.company_id=d.company_id WHERE v.company_id={$company} AND v.id={$vehicle} AND v.deleted_at IS NULL AND d.id={$condition} ORDER BY member.id LIMIT 26 OFFSET " . (($page - 1) * 25));
    }

    public function occurrence(int $company, int $vehicle, int $condition, int $membership): ?array
    {
        $rows = $this->rows("SELECT m.*,i.occurred_at AS incident_occurred_at,i.discovered_at AS incident_discovered_at,i.turo_trip_normalized_id AS trip_id,i.attribution_type,i.overall_note AS attribution_reason,trip.turo_reservation_id,trip.id AS owned_trip_id,ev.event_code,ev.occurred_at AS event_occurred_at,ev.created_at AS event_recorded_at,ev.new_description,ev.new_severity_code FROM {$this->t('vehicle_damage_incident_items')} m JOIN {$this->t('vehicle_damage_items')} d ON d.id=m.vehicle_damage_item_id AND d.company_id=m.company_id JOIN {$this->t('vehicle_damage_incidents')} i ON i.id=m.vehicle_damage_incident_id AND i.company_id=m.company_id AND i.fleet_vehicle_id=d.fleet_vehicle_id JOIN {$this->t('fleet_vehicles')} v ON v.id=d.fleet_vehicle_id AND v.company_id=d.company_id LEFT JOIN {$this->t('turo_trips_normalized')} trip ON trip.id=i.turo_trip_normalized_id AND trip.fleet_vehicle_id=v.id AND trip.deleted_at IS NULL LEFT JOIN {$this->t('vehicle_damage_item_events')} ev ON ev.id=m.condition_event_id AND ev.company_id=m.company_id AND ev.vehicle_damage_item_id=d.id WHERE v.company_id={$company} AND v.id={$vehicle} AND v.deleted_at IS NULL AND d.id={$condition} AND m.id={$membership}");
        return $rows[0] ?? null;
    }

    /** Metadata authorization is performed in SQL once per page, never per image. */
    public function gallery(int $company, int $vehicle, int $condition, ?int $membership, int $after = 0, ?array $selected = null): array
    {
        $scope = $membership === null ? 'e.vehicle_damage_incident_item_id IS NULL' : 'e.vehicle_damage_incident_item_id=' . $membership;
        $selection = $selected === null ? '' : ($selected === [] ? ' AND 1=0' : ' AND e.id IN (' . implode(',', array_map('intval', $selected)) . ')');
        return $this->rows("SELECT e.*,f.id AS file_id,f.storage_disk AS file_storage_disk,f.path AS file_path,f.original_filename AS file_original_filename,f.mime_type AS file_mime_type,f.size_bytes AS file_size_bytes,f.checksum AS file_checksum,f.deleted_at AS file_deleted_at,f.created_at AS uploaded_at
            FROM {$this->t('vehicle_damage_item_evidence')} e JOIN {$this->t('vehicle_damage_items')} d ON d.id=e.vehicle_damage_item_id AND d.company_id=e.company_id JOIN {$this->t('fleet_vehicles')} v ON v.id=d.fleet_vehicle_id AND v.company_id=d.company_id LEFT JOIN {$this->t('vehicle_damage_items')} target ON target.id=d.current_condition_item_id AND target.company_id=d.company_id AND target.fleet_vehicle_id=d.fleet_vehicle_id
            LEFT JOIN {$this->t('vehicle_damage_incident_items')} m ON m.id=e.vehicle_damage_incident_item_id AND m.company_id=e.company_id AND m.vehicle_damage_item_id=d.id
            LEFT JOIN {$this->t('vehicle_damage_incidents')} i ON i.id=m.vehicle_damage_incident_id AND i.company_id=m.company_id AND i.fleet_vehicle_id=d.fleet_vehicle_id
            LEFT JOIN {$this->t('files')} f ON f.id=e.file_id
            WHERE v.company_id={$company} AND v.id={$vehicle} AND v.deleted_at IS NULL AND {$this->episodeScope($condition)} AND {$scope}
            AND (e.vehicle_damage_incident_item_id IS NULL OR i.id IS NOT NULL)
            AND (e.file_id IS NULL OR EXISTS(SELECT 1 FROM {$this->t('vehicle_files')} vf WHERE vf.file_id=e.file_id AND vf.fleet_vehicle_id=v.id))
            AND (e.image_id IS NULL OR EXISTS(SELECT 1 FROM {$this->t('vehicle_images')} vi WHERE vi.image_id=e.image_id AND vi.fleet_vehicle_id=v.id))
            AND e.id>" . max(0, $after) . $selection . ' ORDER BY e.id LIMIT 13');
    }

    public function search(int $company, int $vehicle, array $filters, int $page): array
    {
        $d = $this->t('vehicle_damage_items');
        $v = $this->t('fleet_vehicles');
        $where = "v.company_id={$company} AND v.id={$vehicle} AND v.deleted_at IS NULL";
        $panels = $this->wheelPanels();
        $recorded = $filters['identity'] === 'recorded';
        $identity = $recorded ? 'm' : 'd';
        $select = $recorded ? 'm.*,COALESCE(target.status_code,d.status_code) AS status_code,d.id AS original_condition_id,COALESCE(target.id,d.id) AS canonical_id,i.discovered_at' : 'd.*,d.id AS canonical_id,NULL AS membership_id';
        $join = $recorded ? "JOIN {$this->t('vehicle_damage_incident_items')} m ON m.vehicle_damage_item_id=d.id AND m.company_id=d.company_id JOIN {$this->t('vehicle_damage_incidents')} i ON i.id=m.vehicle_damage_incident_id AND i.company_id=m.company_id AND i.fleet_vehicle_id=d.fleet_vehicle_id LEFT JOIN {$d} target ON target.id=d.current_condition_item_id AND target.company_id=d.company_id AND target.fleet_vehicle_id=d.fleet_vehicle_id AND target.current_condition_item_id IS NULL AND target.id<>d.id AND target.panel_code=d.panel_code AND target.damage_type_code=d.damage_type_code AND target.zone_code=d.zone_code" : '';
        if (!$recorded) {
            $where .= ' AND d.current_condition_item_id IS NULL';
        }
        if ($filters['wheel'] === 'UNKNOWN') {
            $where .= " AND d.zone_code='wheel_tire' AND ({$identity}.panel_code IS NULL OR {$identity}.panel_code NOT IN ({$panels}))";
        } elseif ($filters['wheel'] !== '') {
            $where .= ' AND '.$identity.'.panel_code='.$this->db->escape(VehicleWheelIdentity::panel($filters['wheel']));
        }
        if ($filters['damage_type'] !== '') {
            $where .= ' AND '.$identity.'.damage_type_code='.$this->db->escape($filters['damage_type']);
        }
        $status = match($filters['physical_status']) {
            'current' => " IN ('open','accepted_unrepaired')",'repaired' => "='repaired'",'resolved' => "='resolved_other'",default => null
        };
        if ($status !== null) {
            $where .= ' AND '.($recorded ? 'COALESCE(target.status_code,d.status_code)' : 'd.status_code').$status;
        }
        if ($filters['q'] !== '') {
            $where .= ' AND '.$identity.'.'.($recorded ? 'note' : 'description').' LIKE '.$this->db->escape('%'.$this->db->escapeLikeString($filters['q']).'%')." ESCAPE '!'";
        }
        return $this->rows("SELECT {$select} FROM {$d} d JOIN {$v} v ON v.id=d.fleet_vehicle_id AND v.company_id=d.company_id {$join} WHERE {$where} ORDER BY {$identity}.id DESC LIMIT 26 OFFSET ".(($page - 1) * 25));
    }
}
