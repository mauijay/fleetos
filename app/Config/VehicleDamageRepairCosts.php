<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

class VehicleDamageRepairCosts extends BaseConfig
{
    public const KINDS = ['invoice' => 'Invoice', 'invoice_credit' => 'Invoice credit', 'payment' => 'Vendor payment / deposit', 'payment_refund' => 'Vendor payment refund'];
    public const DOCUMENT_KINDS = ['invoice' => 'invoice', 'invoice_credit' => 'invoice_credit', 'payment' => 'payment_receipt', 'payment_refund' => 'payment_refund'];
    public const EVENTS = ['repair_charge_recorded', 'repair_payment_recorded', 'repair_cost_entry_voided', 'repair_cost_entry_replaced', 'repair_cost_finalized', 'repair_cost_finalization_invalidated'];
    public const MAX_ENTRIES = 1000;
    public const MAX_DUPLICATES = 20;
}
