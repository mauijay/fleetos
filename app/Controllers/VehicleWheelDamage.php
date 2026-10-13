<?php

namespace App\Controllers;

use App\Services\Files\PrivateEvidenceStorageService;
use App\Services\Fleet\VehicleDamageRepairService;
use App\Services\Fleet\VehicleWheelIdentity;
use CodeIgniter\Config\Services as CoreServices;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\DownloadResponse;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\Shield\Config\Services as ShieldServices;
use Config\Services;
use RuntimeException;

class VehicleWheelDamage extends BaseController
{
    public function entry(int $vehicle): string
    {
        $context = $this->context($vehicle);
        $wheel = $context['formData']['wheel_code'] ?? $this->request->getGet('wheel_code') ?? 'LF';
        try {
            $entry = Services::vehicleWheelDamageService()->entry($this->company(), $vehicle, (string) $wheel);
        } catch (\InvalidArgumentException) {
            throw PageNotFoundException::forPageNotFound();
        }
        return CoreServices::renderer()->setData($context + $entry)->render('vehicle_wheel_damage/entry');
    }

    public function command(int $vehicle, string $action): RedirectResponse
    {
        $this->context($vehicle);
        $method = ['new' => 'createNew', 'worsening' => 'recordWorsening', 'unchanged' => 'confirmUnchanged'][$action] ?? throw PageNotFoundException::forPageNotFound();
        return $this->execute($vehicle, $action, fn (array $data): array => Services::vehicleWheelDamageService()->{$method}($this->company(), $vehicle, $data, $this->actor()));
    }

    public function occurrence(int $vehicle, int $condition, int $membership): string
    {
        $context = $this->context($vehicle);
        $member = Services::vehicleDamageOccurrenceRepository()->membership($this->company(), $vehicle, $membership);
        if ($member === null || (int) $member['vehicle_damage_item_id'] !== $condition) {
            throw PageNotFoundException::forPageNotFound();
        }
        $after = filter_var($this->request->getGet('after'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) ?: 0;
        try {
            $photos = Services::vehicleDamageOccurrenceEvidenceService()->gallery($this->company(), $vehicle, $condition, $membership, $after);
        } catch (\Throwable) {
            throw PageNotFoundException::forPageNotFound();
        }
        return CoreServices::renderer()->setData($context + ['member' => $member, 'photos' => $photos])->render('vehicle_wheel_damage/occurrence');
    }

    public function attachPhotos(int $vehicle, int $condition, int $membership): RedirectResponse
    {
        $this->context($vehicle);
        return $this->execute(
            $vehicle,
            'photos:' . $condition . ':' . $membership,
            fn (array $data): array => Services::vehicleWheelDamageService()->attachPhotos($this->company(), $vehicle, $condition, $membership, $data, $this->actor()),
            $this->occurrenceUrl($vehicle, $condition, $membership)
        );
    }

    public function viewPhoto(int $vehicle, int $condition, int $membership, int $evidence): DownloadResponse
    {
        $this->actor();
        $resolved = Services::vehicleDamageOccurrenceEvidenceService()->resolve($this->company(), $vehicle, $condition, $membership, $evidence);
        if ($resolved === null) {
            throw PageNotFoundException::forPageNotFound();
        }
        $metadata = $resolved['metadata'];
        $filename = (new PrivateEvidenceStorageService())->safeResponseFilename($metadata['original_filename'], $metadata['mime_type'], 'wheel-photo');
        $response = $this->response->download($resolved['path'], null)->setFileName($filename)->inline()
            ->setContentType($metadata['mime_type'], '')->setHeader('Cache-Control', 'private, no-store, max-age=0')
            ->setHeader('X-Content-Type-Options', 'nosniff');
        $response->buildHeaders();
        return $response;
    }

    private function execute(int $vehicle, string $action, callable $operation, ?string $failureUrl = null): RedirectResponse
    {
        $data = $this->request->getPost();
        // File objects and descriptors come from the server, never arbitrary POST file IDs.
        unset($data['photos']);
        $data['photos'] = [];
        foreach ($this->request->getFileMultiple('photos') ?? [] as $upload) {
            if ($upload->getError() !== UPLOAD_ERR_NO_FILE) {
                $data['photos'][] = ['upload' => $upload, 'role' => $this->request->getPost('photo_role') ?? 'close_up'];
            }
        }
        $session = CoreServices::session();
        $pending = $session->get($this->retryKey($vehicle));
        if ($pending !== null) {
            if ($pending['action'] !== $action || $this->request->getPost('recover') !== '1') {
                return CoreServices::redirectresponse()->to($failureUrl ?? '/fleet/vehicles/' . $vehicle . '/wheel-damage/new')
                    ->with('wheel_notice', 'Recover the pending save before starting another command.');
            }
            $data = $pending['payload'];
        }
        $result = $operation($data);
        if (($result['uncertain'] ?? false) || ($pending !== null && ! $result['success'])) {
            $session->set($this->retryKey($vehicle), $pending ?? ['action' => $action, 'payload' => $result['retry_payload']]);
            return CoreServices::redirectresponse()->to($failureUrl ?? '/fleet/vehicles/' . $vehicle . '/wheel-damage/new')
                ->with('wheel_notice', 'Save outcome needs verification. Use Recover pending save with its original UUID and photo identity.');
        }
        if (! $result['success']) {
            $formData = array_filter($this->request->getPost(), static fn (mixed $value): bool => is_scalar($value) || $value === null);
            // The route action and original fingerprints describe the rejected intent.
            // A refreshed current slot must not reinterpret New as Worsening.
            $formData['_wheel_action'] = $action;
            return CoreServices::redirectresponse()->to($failureUrl ?? '/fleet/vehicles/' . $vehicle . '/wheel-damage/new?wheel_code=' . rawurlencode((string) ($data['wheel_code'] ?? 'LF')))
                ->with('wheel_errors', $result['errors'])->with('wheel_data', $formData)
                ->with('wheel_notice', 'Selected files must be reselected before retrying. Other form values have been preserved.');
        }
        $session->remove($this->retryKey($vehicle));
        return CoreServices::redirectresponse()->to($this->occurrenceUrl($vehicle, $result['condition_id'], $result['membership_id']))
            ->with('wheel_notice', ($result['replayed'] ?? false) ? 'Original occurrence save recovered.' : 'Wheel occurrence evidence saved.');
    }

    private function context(int $vehicle): array
    {
        $this->actor();
        $owned = Services::vehicleDamageRepository()->vehicle($this->company(), $vehicle);
        if ($owned === null) {
            throw PageNotFoundException::forPageNotFound();
        }
        return ['vehicle' => $owned, 'wheels' => VehicleWheelIdentity::choices(),
            'trips' => Services::vehicleDamageIncidentRepository()->trips($this->company(), $vehicle),
            'assets' => Services::assetManifestService()->appAssets(), 'commandUuid' => VehicleDamageRepairService::commandKey(),
            'errors' => CoreServices::session()->getFlashdata('wheel_errors') ?? [], 'formData' => CoreServices::session()->getFlashdata('wheel_data') ?? [],
            'notice' => CoreServices::session()->getFlashdata('wheel_notice'), 'pending' => CoreServices::session()->get($this->retryKey($vehicle))];
    }

    private function occurrenceUrl(int $vehicle, int $condition, int $membership): string
    {
        return '/fleet/vehicles/' . $vehicle . '/wheel-damage/' . $condition . '/occurrences/' . $membership;
    }

    private function retryKey(int $vehicle): string
    {
        return 'wheel_pending_' . $this->company() . '_' . $vehicle . '_' . $this->actor();
    }

    private function actor(): int
    {
        $user = ShieldServices::auth()->user();
        if ($user === null) {
            throw new RuntimeException('An authenticated operator is required.');
        }
        return (int) $user->id;
    }

    private function company(): int
    {
        $ids = Services::operationalFactsRepository()->activeFleetCompanyIds(date('Y-m-d'));
        if (count($ids) !== 1) {
            throw new RuntimeException('Wheel occurrences require exactly one active fleet company.');
        }
        return $ids[0];
    }
}
