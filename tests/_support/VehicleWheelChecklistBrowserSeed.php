<?php

/** Synthetic seed used only by the guarded loopback browser router. */
if (!isset($db,$directory,$scenario) || !$db instanceof \CodeIgniter\Database\BaseConnection || !is_string($directory)) {
    throw new RuntimeException('Guarded browser context is required.');
}
$asOfFile = $directory.'/as-of-'.$scenario.'.txt';
if (!is_file($asOfFile)) {
    file_put_contents($asOfFile, (new \DateTimeImmutable())->format(DATE_ATOM));
}
$photo = $directory.'/synthetic-wheel.png';
if (!is_file($photo)) {
    $image = imagecreatetruecolor(30, 20);
    imagefill($image, 0, 0, imagecolorallocate($image, 50, 70, 90));
    imagepng($image, $photo);
    imagedestroy($image);
}

if (! $db->tableExists('users')) {
    $db->query('CREATE TABLE users (id INTEGER PRIMARY KEY, username VARCHAR(190))');
    $db->table('users')->insert(['id' => 7,'username' => 'synthetic-wheel-operator']);
}
if ($db->table('trip_movement_checklists')->countAllResults() === 0) {
    \Tests\Support\VehicleWheelChecklistFixture::prepare($db);
    $commands = new \App\Services\Fleet\VehicleWheelDamageService($db);
    $data = static function (string $wheel, string $note) use ($commands): array {
        return ['wheel_code' => $wheel,'note' => $note,'severity_code' => 'cosmetic','discovered_at' => '2026-10-01 10:00:00','expected_wheel_state' => $commands->entry(1, 10, $wheel)['state']['fingerprint'],'command_uuid' => \App\Services\Fleet\VehicleDamageRepairService::commandKey()];
    };
    foreach (['RF','RR'] as $wheel) {
        $result = $commands->createNew(1, 10, $data($wheel, $wheel === 'RF' ? str_repeat('Long recorded rash at 2 o\'clock. ', 45) : 'Synthetic conflicting rash'), 7);
        if (!$result['success']) {
            throw new RuntimeException(json_encode($result));
        }
        if ($wheel === 'RR') {
            $row = $db->table('vehicle_damage_items')->where('id', $result['condition_id'])->get()->getRowArray();
            unset($row['id']);
            $db->table('vehicle_damage_items')->insert($row);
        }
    }
    $damage = new \App\Services\Fleet\VehicleDamageService($db);
    foreach ([['wheel_tire',null,'wheel_curb_rash','Unknown position rash'],['wheel_tire','rear_left_wheel_tire','crack_broken','Other wheel crack'],['front','front_bumper','scratch_scuff','Generic body scratch']] as [$zone,$panel,$type,$note]) {
        $result = $damage->create(1, 10, ['zone_code' => $zone,'panel_code' => $panel,'damage_type_code' => $type,'severity_code' => 'cosmetic','description' => $note,'discovered_at' => '2026-10-01 10:00:00'], 7);
        if (!$result['success']) {
            throw new RuntimeException(json_encode($result));
        }
    }
}
