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
    public int $importFreshnessWarningHours = 24;
    public int $projectionHorizonDays = 30;

    /** @var array<string, array<string, bool>> */
    public array $locationCapabilities = [
        'airport_hnl' => [
            'cleaning' => true,
            'electric_charging' => true,
            'gasoline_refueling' => false,
            'diesel_refueling' => false,
        ],
    ];
}
