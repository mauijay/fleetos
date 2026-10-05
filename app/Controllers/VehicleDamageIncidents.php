<?php

namespace App\Controllers;

use CodeIgniter\Config\Services as CoreServices;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\Shield\Config\Services as ShieldServices;
use Config\Services;
use RuntimeException;

class VehicleDamageIncidents extends BaseController
{
    public function new(int $vehicleId): string
    {
        return $this->render('new', $this->context($vehicleId));
    }

    public function create(int $vehicleId): RedirectResponse
    {
        $result = Services::vehicleDamageIncidentService()->create($this->companyId(), $vehicleId, $this->request->getPost(), $this->actor());

        return $this->result($result, '/fleet/vehicles/' . $vehicleId . '/damage-incidents/' . ($result['id'] ?? 'new'), '/fleet/vehicles/' . $vehicleId . '/damage-incidents/new');
    }

    public function show(int $vehicleId, int $incidentId): string
    {
        $context = $this->context($vehicleId);
        $incident = Services::vehicleDamageIncidentRepository()->incident($this->companyId(), $vehicleId, $incidentId);
        if ($incident === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        return $this->render('show', $context + [
            'incident' => $incident,
            'memberships' => Services::vehicleDamageIncidentRepository()->memberships($this->companyId(), $vehicleId, $incidentId),
            'incidentHistory' => Services::vehicleDamageIncidentRepository()->history($this->companyId(), $vehicleId, $incidentId),
        ]);
    }

    public function attributeTrip(int $vehicleId, int $incidentId): RedirectResponse
    {
        return $this->result(Services::vehicleDamageIncidentService()->attributeTrip($this->companyId(), $vehicleId, $incidentId, $this->request->getPost(), $this->actor()), $this->incidentUrl($vehicleId, $incidentId));
    }

    public function attachArea(int $vehicleId, int $incidentId): RedirectResponse
    {
        $area = $this->request->getPost('area');

        return $this->result(Services::vehicleDamageIncidentService()->attachArea($this->companyId(), $vehicleId, $incidentId, is_array($area) ? $area : [], $this->actor()), $this->incidentUrl($vehicleId, $incidentId));
    }

    public function item(int $vehicleId, int $itemId): string
    {
        $context = $this->context($vehicleId);
        $item = $this->ownedItem($vehicleId, $itemId);

        return $this->render('item', $context + ['item' => $item, 'events' => Services::vehicleDamageRepository()->events($this->companyId(), $itemId), 'evidence' => Services::vehicleDamageRepository()->evidence($this->companyId(), $itemId)]);
    }

    public function linkPreview(int $vehicleId, int $sourceId): string
    {
        $context = $this->context($vehicleId);
        $source = $this->ownedItem($vehicleId, $sourceId);
        $targetId = (int) $this->request->getGet('target_id');
        $target = $targetId > 0 ? $this->ownedItem($vehicleId, $targetId) : null;
        $repo = Services::vehicleDamageRepository();

        return $this->render('link_preview', $context + [
            'source' => $source, 'target' => $target,
            'sourceEvidenceCount' => count($repo->evidence($this->companyId(), $sourceId)),
            'targetEvidenceCount' => $target === null ? 0 : count($repo->evidence($this->companyId(), $targetId)),
        ]);
    }

    public function linkHistorical(int $vehicleId, int $sourceId): RedirectResponse
    {
        $targetId = (int) $this->request->getPost('target_id');
        $result = Services::vehicleDamageIncidentService()->linkHistorical($this->companyId(), $vehicleId, $sourceId, $targetId, $this->request->getPost(), $this->actor());

        return $this->result($result, '/fleet/vehicles/' . $vehicleId . '/damage-incidents/' . ($result['id'] ?? ''), '/fleet/vehicles/' . $vehicleId . '/damage/' . $sourceId . '/link-preview?target_id=' . $targetId);
    }

    public function attachEvidence(int $vehicleId, int $itemId): RedirectResponse
    {
        return $this->result(Services::vehicleDamageService()->attachEvidence($this->companyId(), $vehicleId, $itemId, $this->request->getPost(), $this->actor()), '/fleet/vehicles/' . $vehicleId . '/damage/' . $itemId);
    }

    private function context(int $vehicleId): array
    {
        $companyId = $this->companyId();
        $vehicle = Services::vehicleDamageRepository()->vehicle($companyId, $vehicleId);
        if ($vehicle === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        return [
            'vehicle' => $vehicle, 'vehicleDamage' => Services::vehicleDamageReadService()->workspace($companyId, $vehicleId),
            'trips' => Services::vehicleDamageIncidentRepository()->trips($companyId, $vehicleId),
            'assets' => Services::assetManifestService()->appAssets(),
            'errors' => CoreServices::session()->getFlashdata('damage_incident_errors') ?? [],
            'formData' => CoreServices::session()->getFlashdata('damage_incident_data') ?? [],
            'notice' => CoreServices::session()->getFlashdata('damage_incident_notice'),
        ];
    }

    private function ownedItem(int $vehicleId, int $itemId): array
    {
        return Services::vehicleDamageRepository()->item($this->companyId(), $vehicleId, $itemId) ?? throw PageNotFoundException::forPageNotFound();
    }

    private function render(string $template, array $data): string
    {
        return CoreServices::renderer()->setData($data + ['template' => $template])->render('vehicle_damage_incidents/page');
    }

    private function result(array $result, string $url, ?string $failureUrl = null): RedirectResponse
    {
        if (! $result['success']) {
            return CoreServices::redirectresponse()->to($failureUrl ?? $url)->with('damage_incident_errors', $result['errors'])->with('damage_incident_data', $this->request->getPost());
        }

        return CoreServices::redirectresponse()->to($url)->with('damage_incident_notice', 'Damage history saved.');
    }

    private function incidentUrl(int $vehicleId, int $incidentId): string
    {
        return '/fleet/vehicles/' . $vehicleId . '/damage-incidents/' . $incidentId;
    }

    private function actor(): int
    {
        $user = ShieldServices::auth()->user();
        if ($user === null) {
            throw new RuntimeException('An authenticated operator is required.');
        }

        return (int) $user->id;
    }

    private function companyId(): int
    {
        $ids = Services::operationalFactsRepository()->activeFleetCompanyIds(date('Y-m-d'));
        if (count($ids) !== 1) {
            throw new RuntimeException('Damage incidents require exactly one active fleet company.');
        }

        return $ids[0];
    }
}
