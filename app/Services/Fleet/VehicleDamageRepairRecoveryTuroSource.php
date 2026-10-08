<?php

namespace App\Services\Fleet;

use InvalidArgumentException;

/** No paid-receipt classification or cooperating source-writer protocol is proven yet. */
final class VehicleDamageRepairRecoveryTuroSource
{
    public const ENABLED = false;
    public const VERSION = 'b31-disabled-v1';
    public const UNAVAILABLE = 'Turo recovery recognition is unavailable until paid receipt/reversal semantics and all source writers are validated. Do not enter a Turo payment as an external receipt.';

    public static function recognize(array $normalized, array $raw): never
    {
        throw new InvalidArgumentException(self::UNAVAILABLE);
    }
}
