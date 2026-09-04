<?php

namespace App\Controllers;

use CodeIgniter\Config\Services as CoreServices;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\Shield\Config\Services as ShieldServices;
use Config\Services;

class MovementLocationAliases extends BaseController
{
    public function index(): string
    {
        return CoreServices::renderer()->setData([
            'assets' => Services::assetManifestService()->appAssets(),
            'navigation' => $this->navigation(),
            'sources' => Services::movementLocationAliasService()->unknownSources(),
            'notice' => CoreServices::session()->getFlashdata('movement_location_alias_notice'),
            'error' => CoreServices::session()->getFlashdata('movement_location_alias_error'),
        ])->render('movement_location_aliases/index');
    }

    public function save(): RedirectResponse
    {
        $sourceText = (string) $this->request->getPost('source_text');
        $companyId = (int) $this->request->getPost('company_id');
        $source = $this->selectedUnknownSource($companyId, $sourceText);
        $actorUserId = $this->actorUserId();

        if ($source === null) {
            return $this->redirectWithError('Choose a location from the current unknown-location queue.');
        }
        if ($actorUserId === null) {
            return $this->redirectWithError('An authenticated operator is required to save a location alias.');
        }

        try {
            Services::movementLocationAliasService()->save(
                (int) $source['company_id'],
                (string) $source['source_text'],
                (string) $this->request->getPost('location_class'),
                $this->request->getPost('note'),
                $actorUserId,
            );
        } catch (\InvalidArgumentException $exception) {
            return $this->redirectWithError($exception->getMessage());
        } catch (\Throwable) {
            return $this->redirectWithError('The location alias could not be saved.');
        }

        return CoreServices::redirectresponse()->to('/operations/movement-locations')->with(
            'movement_location_alias_notice',
            'Location alias saved. Matching scheduled movements were reclassified.'
        );
    }

    /** @return array<string, mixed>|null */
    private function selectedUnknownSource(int $companyId, string $sourceText): ?array
    {
        foreach (Services::movementLocationAliasService()->unknownSources() as $source) {
            if ((int) $source['company_id'] === $companyId && hash_equals((string) $source['source_text'], $sourceText)) {
                return $source;
            }
        }

        return null;
    }

    private function redirectWithError(string $message): RedirectResponse
    {
        return CoreServices::redirectresponse()->to('/operations/movement-locations')->with('movement_location_alias_error', $message);
    }

    /** @return array<int, array<string, string>> */
    private function navigation(): array
    {
        return [
            ['label' => 'Fleet Command Center', 'href' => '/', 'active' => 'false'],
            ['label' => 'Fleet Activity', 'href' => '/#fleet-activity', 'active' => 'false'],
            ['label' => 'Vehicles', 'href' => '/fleet/vehicles', 'active' => 'false'],
            ['label' => 'Location Aliases', 'href' => '/operations/movement-locations', 'active' => 'true'],
            ['label' => 'Turo Import', 'href' => '/turo/imports', 'active' => 'false'],
            ['label' => 'Import Issues', 'href' => '/turo/import-issues', 'active' => 'false'],
            ['label' => 'Vehicle Matching', 'href' => '/turo/vehicle-matches', 'active' => 'false'],
            ['label' => 'Revenue', 'href' => '/#financial-snapshot', 'active' => 'false'],
        ];
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
}
