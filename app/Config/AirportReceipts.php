<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

class AirportReceipts extends BaseConfig
{
    /** @var array<int, string> */
    public array $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];

    public int $maxFileSizeBytes = 10485760;

    public string $storageDirectory = 'airport-receipts';
}
