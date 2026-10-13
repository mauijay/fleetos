<?php

namespace App\Controllers;

use App\Services\Fleet\VehicleDamageRepairService;
use App\Services\Fleet\VehicleWheelChecklistService;
use App\Services\Fleet\VehicleWheelIdentity;
use CodeIgniter\Config\Services as CoreServices;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\Shield\Config\Services as ShieldServices;
use Config\Services;
use RuntimeException;

final class VehicleWheelChecklist extends BaseController
{
    public function entry(int $checklist, string $wheel, string $action): string
    {
        $this->actor();
        $company = $this->company();
        $this->method($action);
        $staging = Services::vehicleWheelChecklistService()->forChecklist($company, $checklist) ?? throw PageNotFoundException::forPageNotFound();
        $context = $staging['context'];
        $vehicle = (int) $context['fleet_vehicle_id'];
        $entry = Services::vehicleWheelDamageService()->entry($company, $vehicle, $wheel);
        $projected = $staging['projection']['wheels'][$wheel] ?? null;
        if (($projected['state'] ?? null) === 'conflict') {
            $entry['condition'] = null;
        }
        $session = CoreServices::session();
        $data = $session->getFlashdata('staging_wheel_data') ?? [];
        if (($data['checklist_id'] ?? null) !== $checklist || ($data['wheel_code'] ?? null) !== $wheel || ($data['action'] ?? null) !== $action) {
            $data = [];
        }
        $canRecord = $context['can_record'] && $projected !== null && $projected['state'] !== 'conflict' && $entry['state']['alias_integrity'] === 'valid'
            && ($action === 'new' ? $entry['state']['state'] === 'none' : $entry['state']['state'] === 'one');
        return CoreServices::renderer()->setData($entry + [
            'context' => $context, 'action' => $action, 'canRecord' => $canRecord, 'projected' => $projected,
            'prior' => $projected === null ? null : Services::vehicleWheelChecklistService()->priorComparison($company, $context, $projected),
            'assets' => Services::assetManifestService()->appAssets(), 'commandUuid' => VehicleDamageRepairService::commandKey(),
            'formData' => $data, 'errors' => $session->getFlashdata('staging_wheel_errors') ?? [],
            'notice' => $session->getFlashdata('staging_wheel_notice'), 'pending' => $session->get($this->retryKey($company, $vehicle)),
        ])->render('vehicle_wheel_checklist/entry');
    }

    public function command(int $checklist, string $wheel, string $action): RedirectResponse
    {
        $actor = $this->actor();
        $company = $this->company();
        $method = $this->method($action);
        $context = Services::vehicleWheelChecklistService()->context($company, $checklist) ?? throw PageNotFoundException::forPageNotFound();
        if (! $context['can_record']) {
            return CoreServices::redirectresponse()->to($this->back($checklist))->with('staging_wheel_notice', $context['read_only_reason']);
        }
        $vehicle = (int) $context['fleet_vehicle_id'];
        $wheel = VehicleWheelIdentity::normalize($wheel);
        $projected = Services::vehicleWheelConditionReadService()->forVehicles($company, [$vehicle], [$vehicle => (int) $context['turo_trip_normalized_id']])[$vehicle]['wheels'][$wheel] ?? null;
        if ($projected === null || $projected['state'] === 'conflict' || $projected['alias_integrity'] !== 'valid') {
            return CoreServices::redirectresponse()->to($this->back($checklist))->with('staging_wheel_notice', 'Review conflicting wheel records before recording an occurrence.');
        }
        $session = CoreServices::session();
        $retryKey = $this->retryKey($company, $vehicle);
        $pending = $session->get($retryKey);
        $url = VehicleWheelChecklistService::actionUrl($checklist, $wheel, $action);
        $input = $this->request->getPost();
        $data = array_intersect_key($input, array_flip(['command_uuid', 'expected_wheel_state', 'condition_id', 'expected_condition_state',
            'note', 'severity_code', 'discovered_at', 'occurred_at', 'observed_at', 'attribution_type', 'reason',
            'baseline_event_id', 'baseline_membership_id', 'baseline_evidence_ids', 'baseline_reference', 'photo_role']));
        if (array_key_exists('baseline_reference', $data)) {
            $reference = $data['baseline_reference'];
            $data['baseline_event_id'] = null;
            $data['baseline_membership_id'] = null;
            if ($reference !== '') {
                if (is_string($reference) && preg_match('/^([1-9][0-9]*):([1-9][0-9]*)$/D', $reference, $ids)) {
                    $data['baseline_event_id'] = $ids[1];
                    $data['baseline_membership_id'] = $ids[2];
                } else {
                    $data['baseline_event_id'] = 'invalid';
                }
            }
        }
        $data['photos'] = [];
        foreach ($this->request->getFileMultiple('photos') ?? [] as $upload) {
            if ($upload->getError() !== UPLOAD_ERR_NO_FILE) {
                $data['photos'][] = ['upload' => $upload, 'role' => $input['photo_role'] ?? 'close_up'];
            }
        }
        if ($pending !== null) {
            if ($pending['action'] !== $action || (int) ($pending['payload']['checklist_id'] ?? 0) !== $checklist
                || ($pending['payload']['wheel_code'] ?? null) !== $wheel || ($input['recover'] ?? null) !== '1') {
                return CoreServices::redirectresponse()->to($url)->with('staging_wheel_notice', 'Recover the pending save in its original wheel workflow before another command.');
            }
            $data = $pending['payload'];
        }
        // Only route-derived, owned context enters the Slice 3 command; browser associations are ignored.
        $trusted = ['wheel_code' => $wheel, 'panel_code' => VehicleWheelIdentity::panel($wheel), 'zone_code' => 'wheel_tire', 'damage_type_code' => 'wheel_curb_rash',
            'trip_id' => (int) $context['turo_trip_normalized_id'], 'checklist_id' => $checklist, 'movement_event_id' => null,
            'recovery_exception_id' => null, 'incident_id' => null, 'expected_incident_state' => null];
        $result = Services::vehicleWheelDamageService()->{$method}($company, $vehicle, $data, $actor, $trusted);
        if (($result['uncertain'] ?? false) || ($pending !== null && ! $result['success'])) {
            $session->set($retryKey, $pending ?? ['action' => $action, 'payload' => $result['retry_payload']]);
            return CoreServices::redirectresponse()->to($url)->with('staging_wheel_notice', 'Save outcome needs verification. Recover the pending save using its original command and photo identity.');
        }
        if (! $result['success']) {
            unset($data['photos']);
            $data = array_filter($data, static fn (mixed $value): bool => is_scalar($value) || $value === null);
            // Preserve the originally submitted fingerprints; a stale New never becomes Worsening.
            return CoreServices::redirectresponse()->to($url)->with('staging_wheel_errors', $result['errors'])
                ->with('staging_wheel_data', $data + ['checklist_id' => $checklist, 'wheel_code' => $wheel, 'action' => $action])
                ->with('staging_wheel_notice', 'Review the error before retrying. Reselect any photos. Reload the checklist for current wheel state.');
        }
        $session->remove($retryKey);
        $label = ['new' => 'New curb rash recorded', 'worsening' => 'Worsening recorded', 'unchanged' => 'Unchanged observation recorded'][$action];
        return CoreServices::redirectresponse()->to($this->back($checklist))->with('staging_wheel_notice', VehicleWheelIdentity::label($wheel).': '.$label.'.'.(($result['replayed'] ?? false) ? ' Original save recovered.' : ''));
    }

    private function method(string $action): string
    {
        return ['new' => 'createNew', 'worsening' => 'recordWorsening', 'unchanged' => 'confirmUnchanged'][$action] ?? throw PageNotFoundException::forPageNotFound();
    }

    private function back(int $checklist): string
    {
        return '/operations/checklists/'.$checklist.'#known-vehicle-damage';
    }

    private function retryKey(int $company, int $vehicle): string
    {
        return 'wheel_pending_'.$company.'_'.$vehicle.'_'.$this->actor();
    }

    private function actor(): int
    {
        $user = ShieldServices::auth()->user();
        if ($user === null || (int) $user->id < 1) {
            throw new RuntimeException('An authenticated operator is required.');
        }
        return (int) $user->id;
    }

    private function company(): int
    {
        $ids = Services::operationalFactsRepository()->activeFleetCompanyIds(date('Y-m-d'));
        if (count($ids) !== 1) {
            throw new RuntimeException('Wheel checklist actions require exactly one active fleet company.');
        }
        return $ids[0];
    }
}
