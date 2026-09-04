<?php

namespace App\Controllers;

use CodeIgniter\Config\Services as CoreServices;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\Shield\Config\Services as ShieldServices;
use Config\Services;

class VehiclePositioningPlans extends BaseController
{
    public function show(int $vehicleId): string|RedirectResponse
    {
        $context = Services::vehiclePositioningPlanWorkflowService()->context($vehicleId);
        if ($context === null) {
            return CoreServices::redirectresponse()->to('/fleet/vehicles')->with('error', 'Vehicle not found.');
        }

        return CoreServices::renderer()->setData(array_merge($context, [
            'assets' => Services::assetManifestService()->appAssets(),
            'navigation' => $this->navigation(),
            'notice' => CoreServices::session()->getFlashdata('positioning_plan_notice'),
            'error' => CoreServices::session()->getFlashdata('positioning_plan_error'),
        ]))->render('vehicle_positioning_plans/show');
    }

    public function create(int $vehicleId): RedirectResponse
    {
        $actorUserId = $this->actorUserId();
        if ($actorUserId === null) {
            return $this->redirectWithError($vehicleId, 'An authenticated operator is required to set a positioning plan.');
        }

        try {
            Services::vehiclePositioningPlanWorkflowService()->create($vehicleId, [
                'positioning_code' => $this->request->getPost('positioning_code'),
                'target_location_class' => $this->request->getPost('target_location_class'),
                'reason_code' => $this->request->getPost('reason_code'),
                'note' => $this->request->getPost('note'),
                'transportation_state' => $this->request->getPost('transportation_state'),
                'expires_at' => $this->request->getPost('expires_at'),
            ], $actorUserId);
        } catch (\InvalidArgumentException $exception) {
            return $this->redirectWithError($vehicleId, $exception->getMessage());
        } catch (\Throwable) {
            return $this->redirectWithError($vehicleId, 'The positioning plan could not be saved.');
        }

        return CoreServices::redirectresponse()->to($this->path($vehicleId))->with('positioning_plan_notice', 'Operator positioning plan saved.');
    }

    private function redirectWithError(int $vehicleId, string $message): RedirectResponse
    {
        return CoreServices::redirectresponse()->to($this->path($vehicleId))->with('positioning_plan_error', $message);
    }

    private function actorUserId(): ?int
    {
        try {
            $user = ShieldServices::auth()->user();
        } catch (\Throwable) {
            return null;
        }

        return $user === null || (int) $user->id < 1 ? null : (int) $user->id;
    }

    private function path(int $vehicleId): string
    {
        return '/fleet/vehicles/' . $vehicleId . '/positioning-plan';
    }

    /** @return array<int, array<string, string>> */
    private function navigation(): array
    {
        return [
            ['label' => 'Fleet Command Center', 'href' => '/', 'active' => 'false'],
            ['label' => 'Fleet Activity', 'href' => '/#fleet-activity', 'active' => 'false'],
            ['label' => 'Vehicles', 'href' => '/fleet/vehicles', 'active' => 'true'],
            ['label' => 'Location Aliases', 'href' => '/operations/movement-locations', 'active' => 'false'],
            ['label' => 'Turo Import', 'href' => '/turo/imports', 'active' => 'false'],
            ['label' => 'Import Issues', 'href' => '/turo/import-issues', 'active' => 'false'],
            ['label' => 'Vehicle Matching', 'href' => '/turo/vehicle-matches', 'active' => 'false'],
            ['label' => 'Revenue', 'href' => '/#financial-snapshot', 'active' => 'false'],
        ];
    }
}
