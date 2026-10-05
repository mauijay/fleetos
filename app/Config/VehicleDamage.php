<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

class VehicleDamage extends BaseConfig
{
    public const PANELS = [
        'front_bumper' => 'Front bumper', 'hood' => 'Hood',
        'front_driver_fender' => 'Front driver fender', 'front_passenger_fender' => 'Front passenger fender',
        'front_driver_door' => 'Front driver door', 'front_passenger_door' => 'Front passenger door',
        'rear_driver_door' => 'Rear driver door', 'rear_passenger_door' => 'Rear passenger door',
        'rear_driver_quarter_panel' => 'Rear driver quarter panel', 'rear_passenger_quarter_panel' => 'Rear passenger quarter panel',
        'rear_bumper' => 'Rear bumper', 'trunk_liftgate' => 'Trunk / liftgate',
        'windshield' => 'Windshield', 'roof_glass' => 'Roof / glass',
        'front_left_wheel_tire' => 'Front left wheel / tire', 'front_right_wheel_tire' => 'Front right wheel / tire',
        'rear_left_wheel_tire' => 'Rear left wheel / tire', 'rear_right_wheel_tire' => 'Rear right wheel / tire',
        'interior' => 'Interior', 'underbody' => 'Underbody', 'other' => 'Other',
    ];
    public const ATTRIBUTIONS = [
        'unknown' => 'Unknown',
        'discovered_during_trip' => 'Discovered during trip (cause not established)',
        'suspected_cause' => 'Suspected cause',
        'operator_attributed_cause' => 'Cause attributed by operator',
    ];
    public const EFFECTS = ['new_damage' => 'New damage', 'worsened' => 'Worsens existing', 'observed_existing' => 'Observes existing'];

    public static function zone(string $panel): string
    {
        return match (true) {
            str_contains($panel, 'wheel_tire') => 'wheel_tire',
            str_contains($panel, 'driver') => 'driver_side',
            str_contains($panel, 'passenger') => 'passenger_side',
            in_array($panel, ['front_bumper', 'hood'], true) => 'front',
            in_array($panel, ['rear_bumper', 'trunk_liftgate'], true) => 'rear',
            in_array($panel, ['windshield', 'roof_glass'], true) => 'roof_glass',
            default => $panel,
        };
    }
}
