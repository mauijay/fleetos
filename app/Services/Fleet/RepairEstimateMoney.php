<?php

namespace App\Services\Fleet;

use InvalidArgumentException;

/** Quote amounts never pass through PHP or SQLite floating point. */
final class RepairEstimateMoney
{
    public static function normalize(mixed $amount): string
    {
        if (! is_string($amount) || ! preg_match('/^([0-9]+)(?:\.([0-9]{1,2}))?$/D', trim($amount), $match)) {
            throw new InvalidArgumentException('Enter a decimal quote amount with at most two decimal places.');
        }
        $whole = ltrim($match[1], '0');
        $whole = $whole === '' ? '0' : $whole;
        if (strlen($whole) > 10) {
            throw new InvalidArgumentException('Quote amount exceeds 9999999999.99.');
        }
        return $whole . '.' . str_pad($match[2] ?? '', 2, '0');
    }
}
