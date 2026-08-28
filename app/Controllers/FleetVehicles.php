<?php

namespace App\Controllers;

use CodeIgniter\Config\Services as CoreServices;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\Shield\Config\Services as ShieldServices;
use Config\Services;

class FleetVehicles extends BaseController
{
    public function index(): string
    {
        return CoreServices::renderer()->setData([
            'assets' => Services::assetManifestService()->appAssets(),
            'navigation' => $this->navigation(),
            'vehicles' => Services::fleetVehicleService()->vehicles(),
            'notice' => CoreServices::session()->getFlashdata('fleet_vehicle_notice'),
        ])->render('fleet_vehicles/index');
    }

    public function new(): string|RedirectResponse
    {
        $onboarding = null;
        $turoVehicleId = trim((string) $this->request->getGet('turo_vehicle_id'));
        if ($turoVehicleId !== '') {
            $onboarding = Services::turoVehicleMappingService()->unmatchedVehicle($turoVehicleId);
            if ($onboarding === null) {
                return CoreServices::redirectresponse()->to('/turo/vehicle-matches')->with('turo_vehicle_mapping_error', 'That unresolved Turo vehicle is no longer available for onboarding.');
            }
        }

        $data = CoreServices::session()->getFlashdata('fleet_vehicle_data') ?? $this->onboardingDefaults($onboarding);

        return $this->form(null, $data, CoreServices::session()->getFlashdata('fleet_vehicle_errors') ?? [], $onboarding);
    }

    public function create(): RedirectResponse
    {
        $data = $this->vehicleData();
        $requestedTuroVehicleId = trim((string) $this->request->getPost('onboarding_turo_vehicle_id'));
        $result = $requestedTuroVehicleId === ''
            ? Services::fleetVehicleService()->create($data, $this->actorUserId())
            : Services::unknownVehicleOnboardingService()->onboard($requestedTuroVehicleId, $data, $this->actorUserId());
        if (($result['stage'] ?? null) === 'authority') {
            return CoreServices::redirectresponse()->to('/turo/vehicle-matches')->with('turo_vehicle_mapping_error', $result['errors']['turo_vehicle_id']);
        }
        if (! $result['success']) {
            $turoVehicleId = $result['turo_vehicle_id'] ?? null;
            $target = '/fleet/vehicles/new' . ($turoVehicleId === null ? '' : '?turo_vehicle_id=' . rawurlencode($turoVehicleId));

            return CoreServices::redirectresponse()->to($target)->with('fleet_vehicle_data', $data)->with('fleet_vehicle_errors', $result['errors']);
        }

        if ($requestedTuroVehicleId !== '') {
            $summary = $result['reconciliation']['summary'];
            $relinked = $result['transactions'];
            $message = 'Vehicle created and mapped. Trip reconciliation completed with '
                . ((int) ($summary['successfully_imported'] ?? 0) + (int) ($summary['reconciled_successfully'] ?? 0) + (int) ($summary['already_imported_equivalent'] ?? 0))
                . ' resolved row(s); ' . (int) $relinked['relinked'] . ' transaction(s) relinked.';

            return CoreServices::redirectresponse()->to('/turo/vehicle-matches')->with('turo_vehicle_mapping_notice', $message);
        }

        return CoreServices::redirectresponse()->to('/fleet/vehicles')->with('fleet_vehicle_notice', 'Vehicle created.');
    }

    public function edit(int $id): string
    {
        $vehicle = Services::fleetVehicleService()->vehicle($id);
        if ($vehicle === null) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        return $this->form($vehicle, CoreServices::session()->getFlashdata('fleet_vehicle_data') ?? $vehicle, CoreServices::session()->getFlashdata('fleet_vehicle_errors') ?? [], null);
    }

    public function update(int $id): RedirectResponse
    {
        $data = $this->vehicleData();
        $result = Services::fleetVehicleService()->update($id, $data);
        if (! $result['success']) {
            return CoreServices::redirectresponse()->to("/fleet/vehicles/{$id}/edit")->with('fleet_vehicle_data', $data)->with('fleet_vehicle_errors', $result['errors']);
        }

        return CoreServices::redirectresponse()->to('/fleet/vehicles')->with('fleet_vehicle_notice', 'Vehicle updated.');
    }

    private function form(?array $vehicle, array $data, array $errors, ?array $onboarding): string
    {
        return CoreServices::renderer()->setData([
            'assets' => Services::assetManifestService()->appAssets(),
            'navigation' => $this->navigation(),
            'vehicle' => $vehicle,
            'data' => $data,
            'errors' => $errors,
            'options' => Services::fleetVehicleService()->formOptions(),
            'onboarding' => $onboarding,
        ])->render('fleet_vehicles/form');
    }

    /** @return array<string, mixed> */
    private function vehicleData(): array
    {
        $fields = ['company_id', 'fleet_number', 'fleet_code', 'display_name', 'model_year', 'make_name', 'model_name', 'vehicle_body_style_id', 'exterior_vehicle_color_id', 'interior_vehicle_color_id', 'vehicle_trim_level_id', 'vehicle_drivetrain_id', 'vehicle_status_id', 'vin', 'license_plate', 'purchase_date', 'in_service_date', 'out_of_service_date', 'odometer_miles', 'battery_description', 'seating_capacity'];
        $data = [];
        foreach ($fields as $field) {
            $data[$field] = $this->request->getPost($field);
        }

        return $data;
    }

    private function actorUserId(): ?int
    {
        try {
            $user = ShieldServices::auth()->user();
        } catch (\Throwable) {
            return null;
        }

        return $user === null ? null : (int) $user->id;
    }

    /** @return array<string, mixed> */
    private function onboardingDefaults(?array $onboarding): array
    {
        if ($onboarding === null) {
            return [];
        }

        $options = Services::fleetVehicleService()->formOptions();
        $pending = array_values(array_filter($options['statuses'], static fn (array $status): bool => ($status['code'] ?? null) === 'pending_onboarding'));

        return [
            'model_year' => $onboarding['year'] ?? '',
            'make_name' => $onboarding['make'] ?? '',
            'model_name' => $onboarding['model'] ?? '',
            'license_plate' => $onboarding['license_plate'] ?? '',
            'display_name' => $onboarding['vehicle_name'] ?? '',
            'vehicle_status_id' => $pending[0]['id'] ?? '',
            'seating_capacity' => 5,
        ];
    }

    /** @return array<int, array<string, string>> */
    private function navigation(): array
    {
        return [
            ['label' => 'Fleet Command Center', 'href' => '/', 'active' => 'false'],
            ['label' => 'Vehicles', 'href' => '/fleet/vehicles', 'active' => 'true'],
            ['label' => 'Turo Import', 'href' => '/turo/imports', 'active' => 'false'],
            ['label' => 'Import Issues', 'href' => '/turo/import-issues', 'active' => 'false'],
            ['label' => 'Vehicle Matching', 'href' => '/turo/vehicle-matches', 'active' => 'false'],
        ];
    }
}
