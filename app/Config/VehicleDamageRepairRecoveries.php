<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

class VehicleDamageRepairRecoveries extends BaseConfig
{
    public const KINDS = ['recovery' => 'Recovery received', 'recovery_reversal' => 'Recovery returned / clawed back'];
    public const SOURCES = ['turo_reimbursement' => 'Turo reimbursement (unavailable)', 'guest_direct' => 'Guest direct', 'insurance' => 'Insurance', 'vendor_compensation' => 'Vendor compensation', 'other' => 'Other documented recovery'];
    public const DOCUMENT_KINDS = ['recovery' => 'recovery_payment', 'recovery_reversal' => 'recovery_reversal'];
    public const EVENTS = ['repair_recovery_recorded', 'repair_recovery_reversed', 'repair_recovery_voided', 'repair_recovery_replaced', 'repair_recovery_finalized', 'repair_recovery_finalization_invalidated'];
    public const MAX_ENTRIES = 1000;
    public const MAX_DUPLICATES = 20;
    public const CHARSET = 'utf8mb4';
    public const COLLATION = 'utf8mb4_general_ci';
}
