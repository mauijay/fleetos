<?php

namespace Tests\Unit;

use App\Services\Fleet\VehicleWheelIdentity as Wheel;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VehicleWheelIdentityTest extends TestCase
{
    public static function wheels(): array
    {
        return [['LF', 'front_left_wheel_tire', 'Left front (LF)'], ['RF', 'front_right_wheel_tire', 'Right front (RF)'],
            ['RR', 'rear_right_wheel_tire', 'Right rear (RR)'], ['LR', 'rear_left_wheel_tire', 'Left rear (LR)']];
    }

    #[DataProvider('wheels')]
    public function testMapping(string $code, string $panel, string $label): void
    {
        self::assertSame(['LF', 'RF', 'RR', 'LR'], array_keys(Wheel::choices()));
        self::assertSame($code, Wheel::normalize(' ' . strtolower($code) . ' '));
        self::assertSame($panel, Wheel::panel($code));
        self::assertSame($code, Wheel::code($panel));
        self::assertSame($label, Wheel::label($code));
        self::assertSame($label, Wheel::conditionLabel(['panel_code' => $panel]));
        self::assertSame('Wheel position unknown', Wheel::conditionLabel([]));
        self::assertTrue(Wheel::isRecognizedWheelPanel($panel));
        self::assertSame('wheel_tire', Wheel::zone($code));
        self::assertSame($panel, Wheel::input(['wheel_code' => $code])['panel_code']);
    }

    public static function invalid(): array
    {
        return [['code', 'FL'], ['code', ''], ['panel', 'wheel_tire'], ['panel', 'front_bumper'],
            ['input', ['wheel_code' => 'LF', 'panel_code' => 'rear_left_wheel_tire']], ['input', ['wheel_code' => 'LF', 'zone_code' => 'body_side']]];
    }

    #[DataProvider('invalid')]
    public function testRejectsInvalidOrContradictoryIdentity(string $kind, mixed $value): void
    {
        self::assertFalse(Wheel::isRecognizedWheelPanel(null));
        $this->expectException(InvalidArgumentException::class);
        match ($kind) {
            'code' => Wheel::normalize($value), 'panel' => Wheel::code($value), default => Wheel::input($value)
        };
    }
}
