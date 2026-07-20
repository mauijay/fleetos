<?php

namespace App\Controllers;

use App\DTOs\Turo\ImportResult;
use App\Services\Turo\TuroTripImportService;
use CodeIgniter\HTTP\RedirectResponse;
use Throwable;

class TuroImports extends BaseController
{
    public function index(): string
    {
        return view('turo_imports/index', [
            'assets' => service('assetManifestService')->appAssets(),
            'navigation' => $this->navigation(),
            'result' => session()->getFlashdata('turo_import_result'),
            'error' => session()->getFlashdata('turo_import_error'),
        ]);
    }

    public function store(): RedirectResponse
    {
        $file = $this->request->getFile('trips_csv');

        if ($file === null || ! $file->isValid()) {
            return redirect()->back()->with('turo_import_error', 'Choose a Turo trips CSV file to import.');
        }

        if (! in_array(strtolower($file->getClientExtension()), ['csv', 'txt'], true)) {
            return redirect()->back()->with('turo_import_error', 'Upload a CSV file exported from Turo.');
        }

        $uploadPath = WRITEPATH . 'uploads/turo-imports';
        if (! is_dir($uploadPath)) {
            mkdir($uploadPath, 0775, true);
        }

        $storedName = $file->getRandomName();
        $file->move($uploadPath, $storedName);
        $filePath = $uploadPath . DIRECTORY_SEPARATOR . $storedName;

        try {
            $result = (new TuroTripImportService())->import($filePath, null, $file->getClientName());
        } catch (Throwable $exception) {
            $this->removeUploadedFile($filePath);

            return redirect()->back()->with('turo_import_error', $exception->getMessage());
        }

        $this->removeUploadedFile($filePath);

        return redirect()->to('/turo/imports')->with('turo_import_result', $this->resultSummary($result));
    }

    /** @return array<int, array<string, string>> */
    private function navigation(): array
    {
        return [
            ['label' => 'Fleet Command Center', 'href' => '/', 'active' => 'false'],
            ['label' => 'Turo Import', 'href' => '/turo/imports', 'active' => 'true'],
            ['label' => 'Import Issues', 'href' => '/turo/import-issues', 'active' => 'false'],
            ['label' => 'Vehicle Matching', 'href' => '/turo/vehicle-matches', 'active' => 'false'],
            ['label' => 'Decision Support', 'href' => '/#decision-support', 'active' => 'false'],
            ['label' => 'Fleet', 'href' => '/#fleet-activity', 'active' => 'false'],
            ['label' => 'Reservations', 'href' => '/#fleet-timeline', 'active' => 'false'],
            ['label' => 'Revenue', 'href' => '/#financial-snapshot', 'active' => 'false'],
            ['label' => 'Maintenance', 'href' => '/#fleet-health', 'active' => 'false'],
            ['label' => 'Reports', 'href' => '/#executive-kpis', 'active' => 'false'],
        ];
    }

    /** @return array<string, int> */
    private function resultSummary(ImportResult $result): array
    {
        return [
            'batch_id' => $result->batchId,
            'rows_read' => $result->rowsRead,
            'raw_rows_created' => $result->rawRowsCreated,
            'trips_normalized' => $result->tripsNormalized,
            'allocation_rows_created' => $result->allocationRowsCreated,
            'row_issues' => $result->errorCount,
        ];
    }

    private function removeUploadedFile(string $filePath): void
    {
        if (is_file($filePath)) {
            unlink($filePath);
        }
    }
}
