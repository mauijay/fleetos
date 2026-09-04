<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

class MovementIntelligence extends BaseConfig
{
    /** @var array<string, string> Exact normalized source text => location class. */
    public array $locationAliases = [];

    public int $immediateHours = 24;
    public int $nearTermHours = 72;
    public int $mediumTermHours = 168;
}
