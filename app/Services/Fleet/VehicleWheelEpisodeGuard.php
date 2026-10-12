<?php

namespace App\Services\Fleet;

use App\Repositories\VehicleDamageOccurrenceRepository;
use App\Repositories\VehicleDamageRepairRepository;
use App\Repositories\VehicleDamageRepository;
use CodeIgniter\Database\BaseConnection;
use InvalidArgumentException;

/** Shared command preconditions; callers own the existing vehicle transaction/lock. */
final class VehicleWheelEpisodeGuard
{
    private VehicleDamageRepository $items;

    public function __construct(private readonly BaseConnection $db)
    {
        $this->items = new VehicleDamageRepository($db);
    }

    public static function participates(array $item): bool
    {
        return ($item['damage_type_code'] ?? '') === 'wheel_curb_rash'
            && VehicleWheelIdentity::isRecognizedWheelPanel($item['panel_code'] ?? null);
    }

    public function resolve(int $company, int $vehicle, string $panel, bool $lock = false): array
    {
        return $this->items->activeWheelCurbRashEpisodes($company, $vehicle, $panel, $lock);
    }

    public function assertState(array $state, ?string $expected = null): void
    {
        if ($state['alias_integrity'] !== 'valid') {
            throw new InvalidArgumentException('Invalid alias relationship. Review wheel history before updating.');
        }
        if ($state['state'] === 'conflict') {
            throw new InvalidArgumentException('Conflict: multiple active wheel curb-rash episodes. Review the records before updating.');
        }
        if ($expected !== null && ! hash_equals($state['fingerprint'], $expected)) {
            throw new InvalidArgumentException('Wheel state changed. Reload and review before updating.');
        }
    }

    public function assertEmpty(array $state, ?string $expected = null): void
    {
        $this->assertState($state, $expected);
        if ($state['state'] !== 'none') {
            throw new InvalidArgumentException('An active wheel curb-rash episode already exists. Reload and review; record worsening separately.');
        }
    }

    public function assertActive(array $item, array $state, ?string $expected = null): void
    {
        $this->assertState($state, $expected);
        if (($item['current_condition_item_id'] ?? null) !== null || $state['state'] !== 'one'
            || (int) $state['candidates'][0]['id'] !== (int) $item['id']
            || $state['panel_code'] !== $item['panel_code'] || ! self::participates($item)) {
            throw new InvalidArgumentException('The exact active wheel episode changed. Reload and review the wheel identity.');
        }
    }

    public function assertRequest(array $item, array $data): void
    {
        if (isset($data['wheel_code'])) {
            $data = VehicleWheelIdentity::input($data);
        }
        foreach (['panel_code', 'damage_type_code', 'zone_code'] as $field) {
            if (array_key_exists($field, $data) && $data[$field] !== ($item[$field] ?? null)) {
                throw new InvalidArgumentException('Identity mismatch: request must match the canonical wheel and damage type.');
            }
        }
    }

    /** Capture before waiting for the vehicle lock, including history when the slot is empty. */
    public function intent(array $item, array $values = []): array
    {
        $result = [];
        foreach ([$item, array_replace($item, $values)] as $identity) {
            if (self::participates($identity) && ! isset($result[$identity['panel_code']])) {
                $result[$identity['panel_code']] = $this->resolve((int) $item['company_id'], (int) $item['fleet_vehicle_id'], $identity['panel_code'])['fingerprint'];
            }
        }
        return $result;
    }

    /** Validate every old/new slot immediately before the central condition/event/audit write. */
    public function mutation(array $item, array $values, string $event, array $intent = [], bool $sqliteRepairTransaction = false): void
    {
        $new = array_replace($item, $values);
        $identityChanged = ($item['panel_code'] ?? null) !== ($new['panel_code'] ?? null)
            || $item['damage_type_code'] !== $new['damage_type_code'] || $item['zone_code'] !== $new['zone_code'];
        // Unclassified curb-rash work history is also immutable; unknown position never grants retargeting authority.
        if ($identityChanged && ($item['damage_type_code'] === 'wheel_curb_rash' || $new['damage_type_code'] === 'wheel_curb_rash')) {
            $this->transaction($sqliteRepairTransaction);
            if ($this->hasWork($item)) {
                throw new InvalidArgumentException('Wheel identity has repair/work history. Review required; work history cannot be retargeted.');
            }
        }
        if (! self::participates($item) && ! self::participates($new)) {
            return;
        }
        $this->transaction($sqliteRepairTransaction);
        $company = (int) $item['company_id'];
        $vehicle = (int) $item['fleet_vehicle_id'];
        $this->items->lockVehicle($company, $vehicle);
        if ($identityChanged && $this->db->table('vehicle_damage_items')->where('current_condition_item_id', $item['id'])->countAllResults() > 0) {
            throw new InvalidArgumentException('Wheel identity has historical aliases. Review required before changing its wheel or type.');
        }
        if (self::participates($item)) {
            $old = $this->resolve($company, $vehicle, $item['panel_code'], true);
            $this->assertState($old, $intent[$item['panel_code']] ?? null);
            if (in_array($item['status_code'], ['open', 'accepted_unrepaired'], true) || in_array($event, ['worsened', 'observed_existing'], true)) {
                $this->assertActive($item, $old);
            }
        }
        if (self::participates($new)) {
            if ($new['zone_code'] !== 'wheel_tire') {
                throw new InvalidArgumentException('Identity mismatch: wheel panels require wheel_tire zone.');
            }
            $target = $this->resolve($company, $vehicle, $new['panel_code'], true);
            $this->assertState($target, $intent[$new['panel_code']] ?? null);
            if (($identityChanged || in_array($new['status_code'], ['open', 'accepted_unrepaired'], true))
                && ($target['state'] !== 'none' && (int) $target['candidates'][0]['id'] !== (int) $item['id'])) {
                throw new InvalidArgumentException('Target wheel has an active episode. Reload and review before correcting or reopening.');
            }
        }
    }

    public function historicalLink(array $source, array $target, array $data): bool
    {
        if ($source['damage_type_code'] !== 'wheel_curb_rash' && $target['damage_type_code'] !== 'wheel_curb_rash') {
            return false;
        }
        $this->transaction();
        (new VehicleDamageOccurrenceRepository($this->db))->requireReady();
        if (! self::participates($source) || ! self::participates($target)
            || $source['panel_code'] !== $target['panel_code'] || $source['damage_type_code'] !== $target['damage_type_code']
            || ! in_array($source['status_code'], ['open', 'accepted_unrepaired'], true)
            || ! in_array($target['status_code'], ['open', 'accepted_unrepaired'], true)
            || $this->hasWork($source) || $this->hasWork($target)
            || ($data['same_episode_confirmed'] ?? '') !== '1') {
            throw new InvalidArgumentException('Wheel historical linking requires reviewed same-wheel, same-type, same-episode confirmation without repair/work history or a repair boundary.');
        }
        $state = $this->resolve((int) $source['company_id'], (int) $source['fleet_vehicle_id'], $source['panel_code'], true);
        if ($state['alias_integrity'] !== 'valid' || count($state['candidates']) !== 2
            || array_diff(array_column($state['candidates'], 'id'), [(int) $source['id'], (int) $target['id']]) !== []
            || ! hash_equals($state['fingerprint'], (string) ($data['expected_wheel_state'] ?? ''))
            || ! hash_equals($this->conditionState($source), (string) ($data['source_condition_state'] ?? ''))
            || ! hash_equals($this->conditionState($target), (string) ($data['target_condition_state'] ?? ''))
            || $this->hasRepairBoundary($source) || $this->hasRepairBoundary($target)) {
            throw new InvalidArgumentException('Wheel historical state changed or crosses a repair boundary. Reload and review.');
        }
        return true;
    }

    public function conditionState(array $item): string
    {
        return (new VehicleDamageRepairRepository($this->db))->conditionFingerprint($item);
    }

    private function hasWork(array $item): bool
    {
        return (new VehicleDamageRepairRepository($this->db))->hasAnyMembershipForCondition((int) $item['company_id'], (int) $item['fleet_vehicle_id'], (int) $item['id']);
    }

    private function hasRepairBoundary(array $item): bool
    {
        return $this->db->table('vehicle_damage_item_events')->where('company_id', $item['company_id'])->where('vehicle_damage_item_id', $item['id'])
            ->whereIn('event_code', ['repaired', 'repair_reopened'])->countAllResults() > 0;
    }

    private function transaction(bool $sqliteRepairTransaction = false): void
    {
        if ($this->db->transDepth < 1 && ! ($sqliteRepairTransaction && $this->db->getPlatform() === 'SQLite3')) {
            throw new InvalidArgumentException('Wheel mutation requires the existing command transaction.');
        }
    }
}
