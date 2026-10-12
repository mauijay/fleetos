<?php

/** Included only by the guarded loopback router; all fixtures are synthetic. */
if (PHP_SAPI !== 'cli-server' || !isset($directory,$db) || !str_starts_with(strtolower($directory), strtolower(realpath(dirname(__DIR__, 2).'/build').DIRECTORY_SEPARATOR))) {
    throw new RuntimeException('Synthetic browser seed requires its guarded router.');
}
if (!is_file($directory.'/fixture.json')) {
    $wheel = new \App\Services\Fleet\VehicleWheelDamageService($db);
    $damage = new \App\Services\Fleet\VehicleDamageService($db);
    $data = static function (string $corner, array $changes = []) use ($wheel): array {
        $entry = $wheel->entry(1, 10, $corner);
        return array_replace(['wheel_code' => $corner,'discovered_at' => '2026-09-01 10:00:00','occurred_at' => null,'attribution_type' => 'unknown','severity_code' => 'cosmetic','note' => "Synthetic rash at 2 o'clock. ".str_repeat('Long wheel inspection detail. ', 30),'command_uuid' => \App\Services\Fleet\VehicleDamageRepairService::commandKey(),'expected_wheel_state' => $entry['state']['fingerprint'],'condition_id' => $entry['condition']['id'] ?? null,'expected_condition_state' => $entry['state']['candidates'][0]['fingerprint'] ?? null], $changes);
    };
    $ok = static function (array $receipt): array {
        if (!$receipt['success']) {
            throw new RuntimeException(json_encode($receipt));
        }return $receipt;
    };
    $upload = static function () use ($directory): \CodeIgniter\HTTP\Files\UploadedFile {
        $path = $directory.'/synthetic-upload-'.bin2hex(random_bytes(8)).'.png';
        $image = imagecreatetruecolor(30, 20);
        imagefill($image, 0, 0, imagecolorallocate($image, 40, 60, 80));
        imagepng($image, $path);
        imagedestroy($image);
        return new \CodeIgniter\HTTP\Files\UploadedFile($path, 'synthetic-wheel.png', null, filesize($path), UPLOAD_ERR_OK);
    };
    $new = $ok($wheel->createNew(1, 10, $data('LF'), 7));
    $observed = $ok($wheel->confirmUnchanged(1, 10, $data('LF', ['observed_at' => '2026-09-20 10:00:00','severity_code' => null,'note' => 'Frozen pre-handoff observation.','photos' => [['role' => 'full_wheel','upload' => $upload()]]]), 7));
    foreach (['vehicle_damage_item_events' => $observed['condition_event_id'],'vehicle_damage_incident_items' => $observed['membership_id'],'vehicle_damage_item_evidence' => $observed['evidence_ids'][0]] as $table => $id) {
        $db->table($table)->where('id', $id)->update(['created_at' => '2026-09-20 10:00:00']);
    }
    $db->table('trip_movement_events')->insert(['company_id' => 1,'fleet_vehicle_id' => 10,'turo_trip_normalized_id' => 100,'event_code' => 'actual_handoff','occurred_at' => '2026-09-25 08:00:00','source' => 'operator','actor_user_id' => 7]);
    $changed = $ok($wheel->recordWorsening(1, 10, $data('LF', ['trip_id' => 100,'discovered_at' => '2026-10-01 10:00:00','baseline_event_id' => $observed['condition_event_id'],'baseline_membership_id' => $observed['membership_id'],'baseline_evidence_ids' => $observed['evidence_ids'],'note' => "New delta at 4 o'clock. ".str_repeat('Long after observation detail. ', 40),'photos' => [['role' => 'full_wheel','upload' => $upload()],['role' => 'close_up','upload' => $upload()]]]), 7));
    $late = $ok($wheel->attachPhotos(1, 10, $changed['condition_id'], $changed['membership_id'], ['command_uuid' => \App\Services\Fleet\VehicleDamageRepairService::commandKey(),'photos' => [['role' => 'close_up','upload' => $upload()]]], 7));
    $db->table('vehicle_damage_item_evidence')->where('id', $late['evidence_ids'][0])->update(['created_at' => date('Y-m-d H:i:s', time() + 60)]);
    $photo = $db->table('vehicle_damage_item_evidence')->where('id', $changed['evidence_ids'][0])->get()->getRowArray();
    unset($photo['id']);
    $missing = $photo;
    $missing['file_id'] = null;
    $db->table('vehicle_damage_item_evidence')->insert($missing);
    for ($n = 0;$n < 12;++$n) {
        $db->table('vehicle_damage_item_evidence')->insert($photo);
    }
    $legacy = $photo;
    $legacy['vehicle_damage_incident_item_id'] = null;
    $db->table('vehicle_damage_item_evidence')->insert($legacy);
    $incident = $db->table('vehicle_damage_incidents')->where('id', $changed['incident_id'])->get()->getRowArray();
    unset($incident['id']);
    $member = $db->table('vehicle_damage_incident_items')->where('id', $changed['membership_id'])->get()->getRowArray();
    unset($member['id']);
    $member['condition_event_id'] = null;
    $member['basis_snapshot'] = null;
    for ($n = 0;$n < 28;++$n) {
        $db->table('vehicle_damage_incidents')->insert($incident);
        $member['vehicle_damage_incident_id'] = (int)$db->insertID();
        $db->table('vehicle_damage_incident_items')->insert($member);
    }
    $rf = $ok($wheel->createNew(1, 10, $data('RF'), 7));
    $ok($damage->transitionStatus(1, 10, $rf['condition_id'], 'accepted_unrepaired', 'Synthetic acceptance.', 7));
    $condition = $db->table('vehicle_damage_items')->where('id', $rf['condition_id'])->get()->getRowArray();
    unset($condition['id']);
    $condition['status_code'] = 'resolved_other';
    $condition['description'] = 'Synthetic paginated condition.';
    for ($n = 0;$n < 28;++$n) {
        $db->table('vehicle_damage_items')->insert($condition);
    }
    $rr = $ok($wheel->createNew(1, 10, $data('RR'), 7));
    $ok($damage->transitionStatus(1, 10, $rr['condition_id'], 'repaired', 'Synthetic physical repair.', 7));
    $ok($wheel->createNew(1, 10, $data('RR', ['discovered_at' => date('Y-m-d H:i:s', time() + 60)]), 7));
    $lr = $ok($wheel->createNew(1, 10, $data('LR'), 7));
    $duplicate = $db->table('vehicle_damage_items')->where('id', $lr['condition_id'])->get()->getRowArray();
    unset($duplicate['id']);
    $db->table('vehicle_damage_items')->insert($duplicate);
    $ok($damage->create(1, 10, ['zone_code' => 'wheel_tire','damage_type_code' => 'wheel_curb_rash','severity_code' => 'cosmetic','description' => 'Synthetic unknown wheel.','discovered_at' => '2026-10-01 10:00:00'], 7));
    file_put_contents($directory.'/created-files.json', json_encode(array_map(static fn (array $file): string => rtrim((new \Config\Paths())->writableDirectory, '/\\').'/uploads/'.$file['path'], array_values(array_filter($db->table('files')->get()->getResultArray(), static fn (array $file): bool => str_starts_with($file['path'], 'damage-evidence/company-1/vehicle-10/'))))));
    file_put_contents($directory.'/fixture.json', json_encode(['condition' => $changed['condition_id'],'membership' => $changed['membership_id']]));
}
