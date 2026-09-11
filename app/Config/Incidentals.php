<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

class Incidentals extends BaseConfig
{
    /** Only trips ending on or after activation are eligible for automatic review creation. */
    public string $activationEndedAtUtc = '2026-09-09 00:00:00';

    /** Operational delay for Turo/Tesla data to populate; this is not the filing deadline. */
    public int $reviewDelayMinutes = 1440;

    public int $projectionBatchSize = 500;
}
