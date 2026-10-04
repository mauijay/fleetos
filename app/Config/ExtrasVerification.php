<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

class ExtrasVerification extends BaseConfig
{
    public int $preparationWindowHours = 24;
    public int $maxCompleteAgeHours = 24;
    public int $advisoryHorizonHours = 72;
}
