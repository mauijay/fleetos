<?php

namespace App\Services\Fleet;

use InvalidArgumentException;

/** Unlimited aggregate precision; row normalization remains the B2.2 authority. */
final class RepairCostMoney
{
    public static function add(string $a, string $b): string
    {
        $a = self::digits($a);
        $b = self::digits($b);
        $carry = 0;
        $result = '';
        for ($i = strlen($a) - 1, $j = strlen($b) - 1; $i >= 0 || $j >= 0 || $carry; $i--, $j--) {
            $sum = ($i >= 0 ? (int) $a[$i] : 0) + ($j >= 0 ? (int) $b[$j] : 0) + $carry;
            $result = ($sum % 10) . $result;
            $carry = intdiv($sum, 10);
        }
        return self::decimal($result);
    }

    public static function subtract(string $a, string $b): string
    {
        if (self::compare($a, $b) < 0) {
            throw new InvalidArgumentException('Reduction exceeds the recorded parent amount.');
        }
        $a = self::digits($a);
        $b = self::digits($b);
        $borrow = 0;
        $result = '';
        for ($i = strlen($a) - 1, $j = strlen($b) - 1; $i >= 0; $i--, $j--) {
            $value = (int) $a[$i] - ($j >= 0 ? (int) $b[$j] : 0) - $borrow;
            $borrow = $value < 0 ? 1 : 0;
            $result = ($value + ($borrow ? 10 : 0)) . $result;
        }
        return self::decimal($result);
    }

    public static function compare(string $a, string $b): int
    {
        $a = self::digits($a);
        $b = self::digits($b);
        return strlen($a) <=> strlen($b) ?: strcmp($a, $b);
    }

    private static function digits(string $amount): string
    {
        if (! preg_match('/^(0|[1-9][0-9]*)\.[0-9]{2}$/D', $amount)) {
            throw new InvalidArgumentException('Money must be a canonical decimal string.');
        }
        return ltrim(str_replace('.', '', $amount), '0') ?: '0';
    }

    private static function decimal(string $digits): string
    {
        $digits = str_pad(ltrim($digits, '0') ?: '0', 3, '0', STR_PAD_LEFT);
        return substr($digits, 0, -2) . '.' . substr($digits, -2);
    }
}
