<?php

namespace App\Controllers;

use App\DTOs\Turo\TuroExtrasImportResult;
use CodeIgniter\Config\Services as CoreServices;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\Shield\Config\Services as ShieldServices;
use Config\Services;
use RuntimeException;
use Throwable;

class TuroExtras extends BaseController
{
    public function index(): string
    {
        $companyId = $this->activeCompanyId();

        return CoreServices::renderer()->setData([
            'assets' => Services::assetManifestService()->appAssets(),
            'navigation' => $this->navigation(),
            'workspace' => Services::fleetExtraService()->workspace($companyId),
            'import_result' => CoreServices::session()->getFlashdata('turo_extras_import_result'),
            'success' => CoreServices::session()->getFlashdata('turo_extras_success'),
            'error' => CoreServices::session()->getFlashdata('turo_extras_error'),
        ])->render('turo_extras/index');
    }

    public function import(): RedirectResponse
    {
        $file = $this->request->getFile('extras_json');
        if ($file === null || ! $file->isValid()) {
            return $this->failure('Choose a sanitized FleetOS Turo Extras JSON file.');
        }
        if (strtolower($file->getClientExtension()) !== 'json') {
            return $this->failure('Upload a .json file created by the FleetOS Turo Extras exporter.');
        }
        if ($file->getSize() > 2_097_152) {
            return $this->failure('The sanitized Extras JSON exceeds the 2 MB import limit.');
        }
        $json = file_get_contents($file->getTempName());
        if ($json === false) {
            return $this->failure('FleetOS could not read the uploaded Extras JSON.');
        }
        try {
            $result = Services::turoExtrasImportService()->import(
                $json,
                $this->activeCompanyId(),
                $this->actorUserId(),
                $file->getClientName(),
            );
        } catch (Throwable $exception) {
            return $this->failure($exception->getMessage());
        }

        return CoreServices::redirectresponse()->to('/turo/extras')->with('turo_extras_import_result', $this->resultSummary($result));
    }

    public function createExtra(): RedirectResponse
    {
        try {
            Services::fleetExtraService()->createExtra($this->activeCompanyId(), $this->request->getPost(), $this->actorUserId());
        } catch (Throwable $exception) {
            return $this->failure($exception->getMessage());
        }

        return CoreServices::redirectresponse()->to('/turo/extras')->with('turo_extras_success', 'Canonical Extra created.');
    }

    public function updateExtra(int $extraId): RedirectResponse
    {
        try {
            Services::fleetExtraService()->updateExtra($this->activeCompanyId(), $extraId, $this->request->getPost(), $this->actorUserId());
        } catch (Throwable $exception) {
            return $this->failure($exception->getMessage());
        }

        return CoreServices::redirectresponse()->to('/turo/extras')->with('turo_extras_success', 'Canonical Extra updated.');
    }

    public function mapSource(): RedirectResponse
    {
        try {
            Services::fleetExtraService()->mapSource(
                $this->activeCompanyId(),
                (string) $this->request->getPost('source_extra_id'),
                (int) $this->request->getPost('fleet_extra_id'),
                $this->nullablePost('reason'),
                $this->actorUserId(),
            );
        } catch (Throwable $exception) {
            return $this->failure($exception->getMessage());
        }

        return CoreServices::redirectresponse()->to('/turo/extras')->with('turo_extras_success', 'Turo Extra mapping saved.');
    }

    public function createAndMap(): RedirectResponse
    {
        try {
            Services::fleetExtraService()->createAndMap(
                $this->activeCompanyId(),
                (string) $this->request->getPost('source_extra_id'),
                $this->request->getPost(),
                $this->actorUserId(),
            );
        } catch (Throwable $exception) {
            return $this->failure($exception->getMessage());
        }

        return CoreServices::redirectresponse()->to('/turo/extras')->with('turo_extras_success', 'Canonical Extra created and Turo source ID mapped.');
    }

    private function activeCompanyId(): int
    {
        $companyIds = Services::operationalFactsRepository()->activeFleetCompanyIds(date('Y-m-d'));
        if (count($companyIds) !== 1) {
            throw new RuntimeException('Extras Import requires exactly one active fleet company context.');
        }

        return $companyIds[0];
    }

    private function actorUserId(): int
    {
        $user = ShieldServices::auth()->user();
        if ($user === null) {
            throw new RuntimeException('An authenticated operator is required.');
        }

        return (int) $user->id;
    }

    private function nullablePost(string $name): ?string
    {
        $value = trim((string) $this->request->getPost($name));

        return $value === '' ? null : $value;
    }

    private function failure(string $message): RedirectResponse
    {
        return CoreServices::redirectresponse()->to('/turo/extras')->with('turo_extras_error', $message);
    }

    /** @return array<string,int|bool> */
    private function resultSummary(TuroExtrasImportResult $result): array
    {
        return [
            'batch_id' => $result->batchId,
            'reservations_processed' => $result->reservationsProcessed,
            'selections_added' => $result->selectionsAdded,
            'selections_updated' => $result->selectionsUpdated,
            'selections_unchanged' => $result->selectionsUnchanged,
            'selections_removed' => $result->selectionsRemoved,
            'unmapped_source_extra_ids' => $result->unmappedSourceExtraIds,
            'invalid_reservations' => $result->invalidReservations,
            'export_failures' => $result->exportFailures,
            'duplicate_file' => $result->duplicateFile,
        ];
    }

    /** @return list<array{label:string,href:string,active:string}> */
    private function navigation(): array
    {
        return [
            ['label' => 'Fleet Command Center', 'href' => '/', 'active' => 'false'],
            ['label' => 'Vehicles', 'href' => '/fleet/vehicles', 'active' => 'false'],
            ['label' => 'Turo Import', 'href' => '/turo/imports', 'active' => 'false'],
            ['label' => 'Extras Import', 'href' => '/turo/extras', 'active' => 'true'],
            ['label' => 'Import Issues', 'href' => '/turo/import-issues', 'active' => 'false'],
            ['label' => 'Vehicle Matching', 'href' => '/turo/vehicle-matches', 'active' => 'false'],
            ['label' => 'Reports', 'href' => '/reports/vehicle-performance', 'active' => 'false'],
        ];
    }
}
