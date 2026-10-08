<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

class VehicleDamageRepairDocuments extends BaseConfig
{
    public const KINDS = ['estimate' => 'Estimate', 'work_order' => 'Work order', 'before_photo' => 'Before photo', 'after_photo' => 'After photo', 'other' => 'Other', 'invoice' => 'Invoice', 'invoice_credit' => 'Invoice credit', 'payment_receipt' => 'Vendor payment receipt', 'payment_refund' => 'Vendor payment refund', 'recovery_payment' => 'Recovery payment evidence', 'recovery_reversal' => 'Recovery reversal evidence'];
    public const STATUSES = ['received' => 'Received', 'accepted' => 'Accepted estimate', 'rejected' => 'Rejected', 'superseded' => 'Superseded', 'withdrawn' => 'Withdrawn'];
    public const MODES = ['current_quote' => 'Current quote', 'historical_incomplete' => 'Historical estimate — source details incomplete'];
    public const MIME_TYPES = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];
    public const MAX_BYTES = 10485760;
    public const EVENTS = ['estimate_received', 'estimate_revised', 'estimate_accepted', 'estimate_rejected', 'estimate_withdrawn', 'estimate_superseded', 'document_attached', 'document_archived'];
}
