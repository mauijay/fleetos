<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Local-only admin bootstrap credentials.
 *
 * Copy this file to app/Config/Admin.php and set real values.
 * Keep Admin.php out of version control.
 */
class Admin extends BaseConfig
{
    public string $email = 'owner@example.com';
    public string $password = 'change-this-password';
    public string $username = 'owner';
    public string $group = 'superadmin';
}
