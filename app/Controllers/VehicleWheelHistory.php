<?php

namespace App\Controllers;

use App\Services\Files\PrivateEvidenceStorageService;
use CodeIgniter\Config\Services as CoreServices;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\DownloadResponse;
use CodeIgniter\Shield\Config\Services as ShieldServices;
use Config\Services;
use InvalidArgumentException;
use RuntimeException;

/** Authenticated read surfaces only; no command, staging, or repair semantics. */
class VehicleWheelHistory extends BaseController
{
    public function ledger(int $vehicle): string
    {
        return $this->read(fn (): array => Services::vehicleWheelConditionReadService()->ledger($this->company(), $vehicle, $this->request->getGet(), $this->number('page')), 'ledger');
    }

    public function history(int $vehicle, string $wheel): string
    {
        return $this->read(fn (): array => Services::vehicleWheelConditionReadService()->history($this->company(), $vehicle, $wheel, $this->number('episode_page'), $this->optionalId('episode'), $this->number('timeline_page'), $this->number('repair_page'), $this->cursor('after')), 'history');
    }

    public function occurrence(int $vehicle, int $condition, int $membership): string
    {
        return $this->read(fn (): array => ['vehicle' => ['id' => $vehicle]] + Services::vehicleWheelConditionReadService()->occurrence($this->company(), $vehicle, $condition, $membership, $this->cursor('after'), $this->cursor('baseline_after')), 'occurrence');
    }

    public function episodeEvidence(int $vehicle, int $condition, int $evidence): DownloadResponse
    {
        $this->actor();
        $resolved = Services::vehicleDamageOccurrenceEvidenceService()->resolveEpisodeEvidence($this->company(), $vehicle, $condition, $evidence);
        if ($resolved === null) {
            throw PageNotFoundException::forPageNotFound();
        }
        $metadata = $resolved['metadata'];
        $filename = (new PrivateEvidenceStorageService())->safeResponseFilename($metadata['original_filename'], $metadata['mime_type'], 'wheel-photo');
        $response = $this->response->download($resolved['path'], null)->setFileName($filename)->inline()->setContentType($metadata['mime_type'], '')->setHeader('Cache-Control', 'private, no-store, max-age=0')->setHeader('X-Content-Type-Options', 'nosniff');
        $response->buildHeaders();
        return $response;
    }

    private function read(callable $operation, string $view): string
    {
        $this->actor();
        try {
            $data = $operation();
        } catch (InvalidArgumentException) {
            throw PageNotFoundException::forPageNotFound();
        }
        return CoreServices::renderer()->setData($data + ['assets' => Services::assetManifestService()->appAssets(),'query' => $this->request->getGet()])->render('vehicle_wheel_history/'.$view);
    }

    private function number(string $key, int $default = 1): int
    {
        $raw = $this->request->getGet($key);
        if ($raw === null) {
            return $default;
        }
        if (!is_string($raw) || !preg_match('/^(0|[1-9][0-9]*)$/D', $raw) || strlen($raw) > 9 || (int)$raw < ($default === 0 ? 0 : 1) || (int)$raw > 100000) {
            throw PageNotFoundException::forPageNotFound();
        }
        return (int)$raw;
    }

    private function cursor(string $key): int
    {
        $raw = $this->request->getGet($key);
        return $raw === null || $raw === '0' ? 0 : ($this->optionalId($key) ?? 0);
    }

    private function optionalId(string $key): ?int
    {
        $raw = $this->request->getGet($key);
        if ($raw === null) {
            return null;
        }
        try {
            return \App\Services\Fleet\VehicleDamageOccurrenceBasisSnapshot::positiveId($raw);
        } catch (InvalidArgumentException) {
            throw PageNotFoundException::forPageNotFound();
        }
    }

    private function actor(): void
    {
        if (ShieldServices::auth()->user() === null) {
            throw new RuntimeException('An authenticated operator is required.');
        }
    }

    private function company(): int
    {
        $ids = Services::operationalFactsRepository()->activeFleetCompanyIds(date('Y-m-d'));
        if (count($ids) !== 1) {
            throw new RuntimeException('Wheel history requires one active fleet company.');
        }return $ids[0];
    }
}
