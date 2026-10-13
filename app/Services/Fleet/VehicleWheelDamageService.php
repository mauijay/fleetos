<?php

namespace App\Services\Fleet;

use App\Repositories\VehicleDamageOccurrenceRepository;
use App\Repositories\VehicleDamageRepository;
use App\Repositories\VehicleWheelCommandReceiptRepository;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Explicit occurrence commands; current condition, incident, and media retain their authorities. */
class VehicleWheelDamageService
{
    private readonly BaseConnection $db;
    private readonly VehicleDamageOccurrenceEvidenceService $evidence;

    public function __construct(?BaseConnection $db = null, ?VehicleDamageOccurrenceEvidenceService $evidence = null)
    {
        $this->db = $db ?? Database::connect();
        $this->evidence = $evidence ?? new VehicleDamageOccurrenceEvidenceService($this->db);
    }

    public function createNew(int $company, int $vehicle, array $data, int $actor, array $trustedContext = []): array
    {
        return $this->command('create_new', $company, $vehicle, $data, $actor, $trustedContext);
    }

    public function recordWorsening(int $company, int $vehicle, array $data, int $actor, array $trustedContext = []): array
    {
        return $this->command('record_worsening', $company, $vehicle, $data, $actor, $trustedContext);
    }

    public function confirmUnchanged(int $company, int $vehicle, array $data, int $actor, array $trustedContext = []): array
    {
        return $this->command('confirm_unchanged', $company, $vehicle, $data, $actor, $trustedContext);
    }

    public function attachPhotos(int $company, int $vehicle, int $condition, int $membership, array $data, int $actor): array
    {
        return $this->command('attach_photos', $company, $vehicle, array_replace($data, ['condition_id' => $condition, 'membership_id' => $membership]), $actor);
    }

    /** Selected wheel only, not a four-wheel projection. Pure GET preparation. */
    public function entry(int $company, int $vehicle, string $wheel): array
    {
        $wheel = VehicleWheelIdentity::normalize($wheel);
        $items = new VehicleDamageRepository($this->db);
        $state = (new VehicleWheelEpisodeGuard($this->db))->resolve($company, $vehicle, VehicleWheelIdentity::panel($wheel));
        $condition = count($state['candidates']) === 1 ? $items->item($company, $vehicle, $state['candidates'][0]['id']) : null;
        return ['wheel_code' => $wheel, 'state' => $state, 'condition' => $condition];
    }

    private function command(string $action, int $company, int $vehicle, array $input, int $actor, array $trustedContext = []): array
    {
        $begun = false;
        $commitAttempted = false;
        $data = [];
        try {
            if ($actor < 1 || $company < 1 || $vehicle < 1) {
                throw new InvalidArgumentException('An authenticated operator and owned vehicle are required.');
            }
            $data = $this->normalize($action, array_replace($input, $trustedContext));
            $semantic = $this->semantic($data);
            $hash = hash('sha256', json_encode([$company, $vehicle, $actor, $action, $semantic], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            // A caller may compose photo-free commands in one incident transaction.
            // New binaries need this facade to know the outer commit outcome.
            if ($data['photos'] !== [] && $this->db->transDepth !== 0) {
                throw new InvalidArgumentException('Photo commands require ownership of the outer transaction.');
            }
            if (! $this->db->transBegin()) {
                throw new RuntimeException('Occurrence transaction could not begin.');
            }
            $begun = true;
            $items = new VehicleDamageRepository($this->db);
            $items->lockVehicle($company, $vehicle);
            $receipts = new VehicleWheelCommandReceiptRepository($this->db);
            $prior = $receipts->recover($company, $vehicle, $actor, $action, $data['command_uuid'], $hash);
            if ($prior !== null) {
                $commitAttempted = true;
                $this->commit();
                $this->evidence->release();
                return $prior;
            }
            (new VehicleDamageOccurrenceRepository($this->db))->requireReady();
            if ($action === 'attach_photos') {
                $member = (new VehicleDamageOccurrenceRepository($this->db))->membership($company, $vehicle, $data['membership_id']);
                if ($member === null || (int) $member['vehicle_damage_item_id'] !== $data['condition_id']) {
                    throw new InvalidArgumentException('Choose the original owned occurrence parent.');
                }
                $ids = ['incident_id' => (int) $member['vehicle_damage_incident_id'], 'condition_id' => $data['condition_id'],
                    'membership_id' => $data['membership_id'], 'condition_event_id' => (int) $member['condition_event_id']];
                if ($ids['condition_event_id'] < 1 || $data['photos'] === []) {
                    throw new InvalidArgumentException('Choose an occurrence with exact event provenance and at least one photo.');
                }
            } else {
                $guard = new VehicleWheelEpisodeGuard($this->db);
                $state = $guard->resolve($company, $vehicle, $data['panel_code'], true);
                $guard->assertState($state, $data['expected_wheel_state']);
                $condition = null;
                if ($action === 'create_new') {
                    $guard->assertEmpty($state);
                } else {
                    $condition = $items->item($company, $vehicle, $data['condition_id']);
                    if ($condition === null) {
                        throw new InvalidArgumentException('Choose the expected owned canonical wheel condition.');
                    }
                    $guard->assertRequest($condition, $data);
                    $guard->assertActive($condition, $state, $data['expected_wheel_state']);
                    if (! hash_equals($state['candidates'][0]['fingerprint'], $data['expected_condition_state'])) {
                        throw new InvalidArgumentException('Condition changed. Reload before recording this occurrence.');
                    }
                    if ($action === 'confirm_unchanged') {
                        if ($data['severity_code'] !== null && $data['severity_code'] !== $condition['severity_code']) {
                            throw new InvalidArgumentException('Unchanged observation cannot change severity.');
                        }
                    }
                }
                $contextTrip = $data['trip_id'];
                if ($data['checklist_id'] !== null) {
                    $checklist = (new \App\Repositories\MovementChecklistRepository($this->db))->checklistForCompany($company, $data['checklist_id']);
                    if ($checklist === null || (int) $checklist['fleet_vehicle_id'] !== $vehicle
                        || ($contextTrip !== null && (int) $checklist['turo_trip_normalized_id'] !== $contextTrip)) {
                        throw new InvalidArgumentException('Checklist context must belong to this vehicle, company, and trip.');
                    }
                    $contextTrip = (int) $checklist['turo_trip_normalized_id'];
                }
                $this->repairBoundary($company, $vehicle, $data);
                $priorMembership = null;
                if ($condition !== null) {
                    $row = $this->db->table('vehicle_damage_incident_items')->select('id')->where('company_id', $company)
                        ->where('vehicle_damage_item_id', $condition['id'])->where('panel_code', $data['panel_code'])
                        ->where('damage_type_code', 'wheel_curb_rash')->orderBy('id', 'DESC')->get(1)->getRowArray();
                    $priorMembership = $row === null ? null : (int) $row['id'];
                }
                $snapshot = (new VehicleDamageOccurrenceBasisSnapshot($this->db))->build(
                    $company,
                    $vehicle,
                    $data['panel_code'],
                    'wheel_curb_rash',
                    date('Y-m-d H:i:s'),
                    $condition === null ? null : (int) $condition['id'],
                    $priorMembership,
                    $contextTrip,
                    $data['baseline_event_id'],
                    $data['baseline_membership_id'],
                    $data['baseline_evidence_ids']
                );
                $effect = ['create_new' => 'new_damage', 'record_worsening' => 'worsened', 'confirm_unchanged' => 'observed_existing'][$action];
                $ids = (new VehicleDamageIncidentService($this->db))->writeOccurrenceInTransaction(
                    $company,
                    $vehicle,
                    array_replace($data, ['overall_note' => $data['reason'], 'trip_id' => $contextTrip]),
                    array_replace($data, ['severity_code' => $action === 'confirm_unchanged' ? $condition['severity_code'] : $data['severity_code'], 'effect_code' => $effect, 'vehicle_damage_item_id' => $condition['id'] ?? null]),
                    $actor,
                    VehicleDamageOccurrenceBasisSnapshot::encode($snapshot)
                );
            }
            $evidenceIds = $this->evidence->attach($company, $vehicle, $ids['condition_id'], $ids['membership_id'], $data['photos'], $actor);
            $receipt = ['success' => true, 'errors' => [], 'evidence_ids' => $evidenceIds] + $ids;
            $receipts->record($company, $vehicle, $actor, $action, $data['command_uuid'], $hash, $receipt, array_column($data['photos'], 'descriptor'));
            if (! $this->db->transStatus()) {
                throw new RuntimeException('Occurrence transaction failed.');
            }
            $commitAttempted = true;
            $this->commit();
            $this->evidence->release();
            return $receipt + ['replayed' => false];
        } catch (Throwable $exception) {
            if ($begun && ! $commitAttempted) {
                try {
                    $rolledBack = $this->db->transRollback();
                } catch (Throwable) {
                    $rolledBack = false;
                }
                if ($rolledBack) {
                    $this->evidence->discardAfterRollback();
                } else {
                    $this->evidence->release();
                    return ['success' => false, 'uncertain' => true, 'retry_payload' => $this->semantic($data), 'errors' => ['wheel' => 'Rollback outcome is uncertain. Preserve photos and retry the original command to recover its receipt.']];
                }
            }
            if ($commitAttempted) {
                // Never unlink a possibly committed binary. Descriptor-only retry is replay-only.
                $this->evidence->release();
                return ['success' => false, 'uncertain' => true, 'retry_payload' => $this->semantic($data), 'errors' => ['wheel' => 'Save outcome is uncertain. Retry the original command UUID and frozen photo descriptors to recover its receipt.']];
            }
            return ['success' => false, 'errors' => ['wheel' => $exception instanceof InvalidArgumentException ? $exception->getMessage() : 'Wheel occurrence could not be saved. Reload and retry.']];
        }
    }

    protected function commit(): void
    {
        if (! $this->db->transCommit()) {
            throw new RuntimeException('Occurrence commit acknowledgement unavailable.');
        }
    }

    private function normalize(string $action, array $input): array
    {
        $uuid = strtolower(trim((string) ($input['command_uuid'] ?? '')));
        if (! preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $uuid)) {
            throw new InvalidArgumentException('A valid command UUID is required. Reload the entry form.');
        }
        $photos = $this->evidence->normalize($input['photos'] ?? []);
        if (count($photos) > VehicleDamageOccurrenceEvidenceService::MAX_MEMBERSHIP_IMAGES) {
            throw new InvalidArgumentException('Choose at most 6 photos for this occurrence.');
        }
        if ($action === 'attach_photos') {
            return ['command_uuid' => $uuid, 'photos' => $photos, 'condition_id' => VehicleDamageOccurrenceBasisSnapshot::positiveId($input['condition_id'] ?? null),
                'membership_id' => VehicleDamageOccurrenceBasisSnapshot::positiveId($input['membership_id'] ?? null)];
        }
        $identity = VehicleWheelIdentity::input($input);
        if (array_key_exists('damage_type_code', $input) && $input['damage_type_code'] !== 'wheel_curb_rash') {
            throw new InvalidArgumentException('Wheel commands require Wheel curb rash.');
        }
        $discovered = $this->time($action === 'confirm_unchanged' ? ($input['observed_at'] ?? null) : ($input['discovered_at'] ?? null));
        $occurred = empty($input['occurred_at']) ? null : $this->time($input['occurred_at']);
        if ($occurred !== null && $occurred > $discovered) {
            throw new InvalidArgumentException('Known occurrence time must be no later than discovery.');
        }
        $attribution = (string) ($input['attribution_type'] ?? 'unknown');
        if ($action === 'confirm_unchanged' && ! in_array($attribution, ['unknown', 'discovered_during_trip'], true)) {
            throw new InvalidArgumentException('Confirm unchanged records a noncausal observation; it cannot assign responsibility.');
        }
        if ($action === 'confirm_unchanged' && $occurred !== null) {
            throw new InvalidArgumentException('An unchanged observation is not a damage occurrence time.');
        }
        $severity = $input['severity_code'] ?? null;
        if (($action !== 'confirm_unchanged' || $severity !== null) && ! isset(VehicleDamageService::SEVERITIES[$severity ?? ''])) {
            throw new InvalidArgumentException('Choose a valid severity.');
        }
        $baseline = $input['baseline_evidence_ids'] ?? [];
        if (! is_array($baseline) || ! array_is_list($baseline) || count($baseline) > 24) {
            throw new InvalidArgumentException('Choose at most 24 baseline evidence references.');
        }
        $baseline = array_map(VehicleDamageOccurrenceBasisSnapshot::positiveId(...), $baseline);
        $reason = self::text($input['reason'] ?? null, in_array($attribution, ['suspected_cause', 'operator_attributed_cause'], true));
        return ['command_uuid' => $uuid, 'wheel_code' => VehicleWheelIdentity::normalize($input['wheel_code']), 'panel_code' => $identity['panel_code'],
            'zone_code' => 'wheel_tire', 'damage_type_code' => 'wheel_curb_rash', 'discovered_at' => $discovered,
            'observed_at' => $action === 'confirm_unchanged' ? $discovered : null, 'occurred_at' => $occurred,
            'severity_code' => $severity, 'note' => self::text($input['note'] ?? $input['description'] ?? null, true),
            'attribution_type' => $attribution, 'reason' => $reason, 'trip_id' => $this->optionalId($input['trip_id'] ?? null),
            'checklist_id' => $this->optionalId($input['checklist_id'] ?? null), 'movement_event_id' => $this->optionalId($input['movement_event_id'] ?? null), 'recovery_exception_id' => $this->optionalId($input['recovery_exception_id'] ?? null),
            'condition_id' => $action === 'create_new' ? null : VehicleDamageOccurrenceBasisSnapshot::positiveId($input['condition_id'] ?? null),
            'expected_wheel_state' => $this->fingerprint($input['expected_wheel_state'] ?? null),
            'expected_condition_state' => $action === 'create_new' ? null : $this->fingerprint($input['expected_condition_state'] ?? null),
            'incident_id' => $this->optionalId($input['incident_id'] ?? null), 'expected_incident_state' => $input['expected_incident_state'] ?? null,
            'baseline_event_id' => $this->optionalId($input['baseline_event_id'] ?? null), 'baseline_membership_id' => $this->optionalId($input['baseline_membership_id'] ?? null),
            'baseline_evidence_ids' => $baseline, 'photos' => $photos];
    }

    private function semantic(array $data): array
    {
        $data['photos'] = array_map(static fn (array $photo): array => array_intersect_key($photo, array_flip(['role', 'descriptor'])), $data['photos'] ?? []);
        return $data;
    }

    private function repairBoundary(int $company, int $vehicle, array $data): void
    {
        $repair = $this->db->table('vehicle_damage_item_events events')->select('events.occurred_at')
            ->join('vehicle_damage_items items', 'items.id=events.vehicle_damage_item_id AND items.company_id=events.company_id')
            ->where('items.company_id', $company)->where('items.fleet_vehicle_id', $vehicle)->where('items.panel_code', $data['panel_code'])
            ->where('items.damage_type_code', 'wheel_curb_rash')->where('events.event_code', 'repaired')->orderBy('events.occurred_at', 'DESC')->get(1)->getRowArray();
        if ($repair !== null && ($data['discovered_at'] <= $repair['occurred_at'] || ($data['occurred_at'] !== null && $data['occurred_at'] <= $repair['occurred_at']))) {
            throw new InvalidArgumentException('This report predates or crosses a documented wheel repair boundary. Review the historical report.');
        }
    }

    private static function text(mixed $input, bool $required = false): ?string
    {
        if ($input !== null && ! is_string($input)) {
            throw new InvalidArgumentException('Description and reason must contain text.');
        }
        $text = trim($input ?? '');
        if (mb_strlen($text) > 2000 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $text) || ($required && $text === '')) {
            throw new InvalidArgumentException('Supply valid text of 1 through 2000 characters.');
        }
        return $text === '' ? null : $text;
    }

    private function optionalId(mixed $input): ?int
    {
        return $input === null || $input === '' ? null : VehicleDamageOccurrenceBasisSnapshot::positiveId($input);
    }

    private function fingerprint(mixed $input): string
    {
        if (! is_string($input) || ! preg_match('/^[a-f0-9]{64}$/D', $input)) {
            throw new InvalidArgumentException('Expected wheel and condition fingerprints are required. Reload the form.');
        }
        return $input;
    }

    private function time(mixed $input): string
    {
        foreach (['!Y-m-d H:i:s', '!Y-m-d\TH:i'] as $format) {
            $date = is_string($input) ? DateTimeImmutable::createFromFormat($format, $input, new DateTimeZone('Pacific/Honolulu')) : false;
            if ($date !== false && $date->format(substr($format, 1)) === $input) {
                return $date->format('Y-m-d H:i:s');
            }
        }
        throw new InvalidArgumentException('Supply a valid Honolulu date and time.');
    }
}
