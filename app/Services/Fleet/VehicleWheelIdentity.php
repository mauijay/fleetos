<?php

namespace App\Services\Fleet;

use InvalidArgumentException;

/** Left/right are viewed from the vehicle facing forward. */
final class VehicleWheelIdentity
{
    private const WHEELS = [
        'LF' => ['panel_code' => 'front_left_wheel_tire', 'label' => 'Left front (LF)'],
        'RF' => ['panel_code' => 'front_right_wheel_tire', 'label' => 'Right front (RF)'],
        'RR' => ['panel_code' => 'rear_right_wheel_tire', 'label' => 'Right rear (RR)'],
        'LR' => ['panel_code' => 'rear_left_wheel_tire', 'label' => 'Left rear (LR)'],
    ];

    public static function choices(): array
    {
        return self::WHEELS;
    }

    public static function normalize(string $code): string
    {
        $code = strtoupper(trim($code));
        if (! isset(self::WHEELS[$code])) {
            throw new InvalidArgumentException('Choose LF, RF, RR or LR.');
        }
        return $code;
    }

    public static function panel(string $code): string
    {
        return self::WHEELS[self::normalize($code)]['panel_code'];
    }

    public static function code(string $panel): string
    {
        foreach (self::WHEELS as $code => $wheel) {
            if ($wheel['panel_code'] === $panel) {
                return $code;
            }
        }
        throw new InvalidArgumentException('Wheel position unknown. Select an exact wheel panel for classification.');
    }

    public static function label(string $code): string
    {
        return self::WHEELS[self::normalize($code)]['label'];
    }

    public static function isRecognizedWheelPanel(?string $panel): bool
    {
        return in_array($panel, array_column(self::WHEELS, 'panel_code'), true);
    }

    public static function zone(string $code): string
    {
        self::normalize($code);
        return 'wheel_tire';
    }

    public static function conditionLabel(array $item): string
    {
        $panel = $item['panel_code'] ?? null;
        return self::isRecognizedWheelPanel($panel) ? self::label(self::code($panel)) : 'Wheel position unknown';
    }

    /** Dedicated wheel input must never silently override a contradictory panel/zone. */
    public static function input(array $data): array
    {
        $code = self::normalize((string) ($data['wheel_code'] ?? ''));
        $panel = self::panel($code);
        if ((array_key_exists('panel_code', $data) && $data['panel_code'] !== $panel)
            || (array_key_exists('zone_code', $data) && $data['zone_code'] !== self::zone($code))) {
            throw new InvalidArgumentException('Identity mismatch: wheel code, panel and zone must agree.');
        }
        return array_replace($data, ['wheel_code' => $code, 'panel_code' => $panel, 'zone_code' => self::zone($code)]);
    }
}
