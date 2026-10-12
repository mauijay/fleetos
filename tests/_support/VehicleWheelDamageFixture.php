<?php

namespace Tests\Support;

use App\Repositories\VehicleDamageOccurrenceRepository;
use App\Services\Fleet\VehicleWheelDamageService;
use CodeIgniter\HTTP\Files\UploadedFile;

final class VehicleWheelDamageFixture
{
    public VehicleDamageOccurrenceFixture $schema;
    public VehicleWheelDamageService $service;
    private array $temporary = [];

    public function __construct(string $engine = 'sqlite')
    {
        $this->schema = new VehicleDamageOccurrenceFixture($engine);
        $this->schema->migrate();
        $this->service = new VehicleWheelDamageService($this->schema->db);
    }

    public function data(string $wheel = 'LF', array $changes = []): array
    {
        $entry = $this->service->entry(1, 10, $wheel);
        return array_replace(['wheel_code' => $wheel, 'discovered_at' => '2026-10-01 10:00:00', 'occurred_at' => null,
            'attribution_type' => 'unknown', 'severity_code' => 'cosmetic', 'note' => "Synthetic rash at 2 o'clock.",
            'command_uuid' => \App\Services\Fleet\VehicleDamageRepairService::commandKey(), 'expected_wheel_state' => $entry['state']['fingerprint'],
            'condition_id' => $entry['condition']['id'] ?? null, 'expected_condition_state' => $entry['state']['candidates'][0]['fingerprint'] ?? null], $changes);
    }

    public function member(array $receipt): array
    {
        return (new VehicleDamageOccurrenceRepository($this->schema->db))->membership(1, 10, $receipt['membership_id']);
    }

    public function upload(string $format = 'png', ?string $name = null): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'wheel-image-');
        $image = imagecreatetruecolor(30, 20);
        imagefill($image, 0, 0, imagecolorallocate($image, 50, 70, 90));
        match ($format) {
            'jpg' => imagejpeg($image, $path),
            'webp' => imagewebp($image, $path),
            default => imagepng($image, $path),
        };
        imagedestroy($image);
        $this->temporary[] = $path;
        return new UploadedFile($path, $name ?? ('synthetic-wheel.' . $format), null, filesize($path), UPLOAD_ERR_OK);
    }

    public function close(): void
    {
        foreach ($this->schema->db->table('files')->get()->getResultArray() as $file) {
            if (str_starts_with($file['path'], 'damage-evidence/company-1/vehicle-10/')) {
                $path = rtrim((new \Config\Paths())->writableDirectory, '/\\') . '/uploads/' . $file['path'];
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
        foreach ($this->temporary as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        $this->schema->close();
    }
}
