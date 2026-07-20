<?php

namespace App\Controllers;

use CodeIgniter\HTTP\RedirectResponse;

class TuroVehicleMatches extends BaseController
{
    public function index(): string
    {
        return view('turo_vehicle_matches/index', [
            'assets' => service('assetManifestService')->appAssets(),
            'navigation' => $this->navigation(),
            'queue' => service('turoVehicleMappingService')->queue($this->filters()),
            'notice' => session()->getFlashdata('turo_vehicle_mapping_notice'),
            'error' => session()->getFlashdata('turo_vehicle_mapping_error'),
        ]);
    }

    public function map(): RedirectResponse
    {
        $result = service('turoVehicleMappingService')->map(
            (string) $this->request->getPost('turo_vehicle_id'),
            (int) $this->request->getPost('fleet_vehicle_id'),
            $this->request->getPost('confirm_remap') === '1',
            $this->request->getPost('mapping_note'),
        );

        return redirect()->back()->with(
            $result['success'] ? 'turo_vehicle_mapping_notice' : 'turo_vehicle_mapping_error',
            $result['message']
        );
    }

    public function resolveRelated(): RedirectResponse
    {
        return redirect()->to('/turo/vehicle-matches/reprocess?turo_vehicle_id=' . rawurlencode((string) $this->request->getPost('turo_vehicle_id')));
    }

    public function reprocessPreview(): string
    {
        $turoVehicleId = (string) $this->request->getGet('turo_vehicle_id');

        return view('turo_vehicle_matches/reprocess', [
            'assets' => service('assetManifestService')->appAssets(),
            'navigation' => $this->navigation(),
            'preview' => service('turoTripReconciliationService')->preview($turoVehicleId),
            'result' => session()->getFlashdata('turo_reprocess_result'),
            'error' => session()->getFlashdata('turo_vehicle_mapping_error'),
        ]);
    }

    public function reprocess(): RedirectResponse
    {
        $turoVehicleId = (string) $this->request->getPost('turo_vehicle_id');
        $confirmed = $this->request->getPost('confirm_reprocess') === '1';

        if (! $confirmed) {
            return redirect()->back()->with('turo_vehicle_mapping_error', 'Confirm before reprocessing eligible historical rows.');
        }

        $result = service('turoTripReconciliationService')->execute($turoVehicleId, $this->request->getPost('resolution_note'));

        return redirect()
            ->to('/turo/vehicle-matches/reprocess?turo_vehicle_id=' . rawurlencode($turoVehicleId))
            ->with('turo_reprocess_result', $result);
    }

    /** @return array<string, mixed> */
    private function filters(): array
    {
        return [
            'status' => $this->request->getGet('status'),
            'fleet_vehicle_id' => $this->request->getGet('fleet_vehicle_id'),
            'batch_id' => $this->request->getGet('batch_id'),
            'vehicle' => $this->request->getGet('vehicle'),
            'from' => $this->request->getGet('from'),
            'to' => $this->request->getGet('to'),
        ];
    }

    /** @return array<int, array<string, string>> */
    private function navigation(): array
    {
        return [
            ['label' => 'Fleet Command Center', 'href' => '/', 'active' => 'false'],
            ['label' => 'Turo Import', 'href' => '/turo/imports', 'active' => 'false'],
            ['label' => 'Import Issues', 'href' => '/turo/import-issues', 'active' => 'false'],
            ['label' => 'Vehicle Matching', 'href' => '/turo/vehicle-matches', 'active' => 'true'],
            ['label' => 'Fleet', 'href' => '/#fleet-activity', 'active' => 'false'],
            ['label' => 'Revenue', 'href' => '/#financial-snapshot', 'active' => 'false'],
        ];
    }
}
