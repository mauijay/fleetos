<?php

namespace App\Repositories;

use CodeIgniter\Database\BaseConnection;
use InvalidArgumentException;
use RuntimeException;

/** Versioned receipts in the existing parent-authorized audit history. */
final class VehicleWheelCommandReceiptRepository
{
    public function __construct(private readonly BaseConnection $db)
    {
    }

    public function recover(int $company, int $vehicle, int $actor, string $action, string $uuid, string $hash): ?array
    {
        $builder = $this->db->table('audit_logs audit')->select('audit.*, incidents.company_id, incidents.fleet_vehicle_id')
            ->join('vehicle_damage_incidents incidents', "audit.table_name='vehicle_damage_incidents' AND incidents.id=audit.record_id")
            ->where('incidents.company_id', $company)->like('audit.new_values', $uuid, 'both')->orderBy('audit.id')->limit(2);
        $result = $this->db->query($builder->getCompiledSelect() . ($this->db->getPlatform() === 'SQLite3' ? '' : ' FOR UPDATE'));
        if ($result === false) {
            throw new RuntimeException('Wheel command receipt could not be verified.');
        }
        $rows = $result->getResultArray();
        if (count($rows) > 1) {
            throw new RuntimeException('Duplicate wheel command receipts require audit review.');
        }
        if ($rows === []) {
            return null;
        }
        $row = $rows[0];
        $command = json_decode($row['new_values'], true, 512, JSON_THROW_ON_ERROR)['wheel_command'] ?? null;
        $receipt = $command['receipt'] ?? null;
        if (! is_array($command) || ($command['version'] ?? null) !== 1 || ($command['command_uuid'] ?? null) !== $uuid
            || ! is_array($receipt) || ($receipt['success'] ?? null) !== true || ($receipt['errors'] ?? null) !== []
            || ! is_array($receipt['evidence_ids'] ?? null) || ! array_is_list($receipt['evidence_ids'])
            || count($receipt['evidence_ids']) > 6 || ! is_array($command['evidence_descriptors'] ?? null) || ! array_is_list($command['evidence_descriptors'])
            || count($command['evidence_descriptors']) !== count($receipt['evidence_ids']) || (int) ($receipt['incident_id'] ?? 0) !== (int) $row['record_id']) {
            throw new RuntimeException('Malformed wheel command receipt requires audit review.');
        }
        foreach (['incident_id', 'condition_id', 'condition_event_id', 'membership_id'] as $field) {
            if (! is_int($receipt[$field] ?? null) || $receipt[$field] < 1) {
                throw new RuntimeException('Malformed occurrence IDs require audit review.');
            }
        }
        if (count(array_unique($receipt['evidence_ids'], SORT_REGULAR)) !== count($receipt['evidence_ids'])) {
            throw new RuntimeException('Malformed photo IDs require audit review.');
        }
        foreach ($receipt['evidence_ids'] as $id) {
            if (! is_int($id) || $id < 1) {
                throw new RuntimeException('Malformed photo IDs require audit review.');
            }
        }
        foreach ($command['evidence_descriptors'] as $descriptor) {
            if (! is_array($descriptor) || ! is_string($descriptor['checksum'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/D', $descriptor['checksum'])
                || ! in_array($descriptor['mime_type'] ?? null, \App\Services\Fleet\VehicleDamageOccurrenceEvidenceService::MIME_TYPES, true)
                || ! is_int($descriptor['size_bytes'] ?? null) || $descriptor['size_bytes'] < 1 || $descriptor['size_bytes'] > 10485760
                || ! is_string($descriptor['original_filename'] ?? null) || $descriptor['original_filename'] === '' || strlen($descriptor['original_filename']) > 190 || preg_match('/[\x00-\x1F\x7F]/', $descriptor['original_filename'])
                || ! is_int($descriptor['width'] ?? null) || ! is_int($descriptor['height'] ?? null) || $descriptor['width'] < 1 || $descriptor['height'] < 1
                || $descriptor['width'] > 10000 || $descriptor['height'] > 10000 || $descriptor['width'] * $descriptor['height'] > 20000000) {
                throw new RuntimeException('Malformed photo descriptors require audit review.');
            }
        }
        if (($command['company_id'] ?? null) !== $company || ($command['vehicle_id'] ?? null) !== $vehicle
            || (int) $row['fleet_vehicle_id'] !== $vehicle || ($command['actor_id'] ?? null) !== $actor || (int) $row['actor_user_id'] !== $actor
            || ($command['action'] ?? null) !== $action || ! is_string($command['payload_hash'] ?? null) || ! hash_equals($command['payload_hash'], $hash)) {
            throw new InvalidArgumentException('Command UUID was already used with another actor, context, or payload.');
        }
        $member = (new VehicleDamageOccurrenceRepository($this->db))->membership($company, $vehicle, $receipt['membership_id']);
        if ($member === null || (int) $member['vehicle_damage_incident_id'] !== $receipt['incident_id']
            || (int) $member['vehicle_damage_item_id'] !== $receipt['condition_id'] || (int) $member['condition_event_id'] !== $receipt['condition_event_id']) {
            throw new RuntimeException('Receipt parents no longer agree. Review occurrence history.');
        }
        $expectedEffect = ['create_new' => 'new_damage', 'record_worsening' => 'worsened', 'confirm_unchanged' => 'observed_existing'][$action] ?? null;
        if ($expectedEffect !== null && $member['effect_code'] !== $expectedEffect) {
            throw new RuntimeException('Receipt action no longer agrees with its occurrence.');
        }
        if ($receipt['evidence_ids'] !== []) {
            $photos = $this->db->table('vehicle_damage_item_evidence')->select('id')->where('company_id', $company)
                ->where('vehicle_damage_item_id', $receipt['condition_id'])->where('vehicle_damage_incident_item_id', $receipt['membership_id'])
                ->whereIn('id', $receipt['evidence_ids'])->get()->getResultArray();
            if (count($photos) !== count($receipt['evidence_ids'])) {
                throw new RuntimeException('Receipt evidence parents require audit review.');
            }
        }
        return $receipt + ['replayed' => true];
    }

    public function record(int $company, int $vehicle, int $actor, string $action, string $uuid, string $hash, array $receipt, array $descriptors): void
    {
        (new AuditLogRepository($this->db))->record(
            $actor,
            (new LookupRepository($this->db))->valueId('audit_action', 'created'),
            'vehicle_damage_incidents',
            $receipt['incident_id'],
            null,
            ['wheel_command' => [
                'version' => 1, 'command_uuid' => $uuid, 'company_id' => $company, 'vehicle_id' => $vehicle,
                'actor_id' => $actor, 'action' => $action, 'payload_hash' => $hash, 'receipt' => $receipt, 'evidence_descriptors' => $descriptors,
            ]]
        );
    }
}
