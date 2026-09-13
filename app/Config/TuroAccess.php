<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

class TuroAccess extends BaseConfig
{
    /** Current HNL policy does not support creation of new Turo Access claims. */
    public bool $newReimbursementClaimsEnabled = false;

    /** Current HNL host parking fee, deducted from host earnings as an operating cost. */
    public float $hostParkingFeeAmount = 14.00;

    public string $currentHnlPolicyUrl = 'https://help.turo.com/honolulu-international-airport-hnl-hosts-SyLAyUnLkg';

    /** Legacy claim cap retained only to preserve historical claim calculations. */
    public float $reimbursementCapAmount = 21.00;
    public string $capEffectiveOn = '2026-07-19';
}
