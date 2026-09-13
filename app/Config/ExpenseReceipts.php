<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

class ExpenseReceipts extends BaseConfig
{
    /** @var list<string> */
    public array $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];

    public int $maxFileSizeBytes = 10485760;

    public string $storageDirectory = 'operating-expense-receipts';
}
