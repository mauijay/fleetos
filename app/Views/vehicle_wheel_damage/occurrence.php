<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Wheel occurrence evidence | FleetOS</title>
<?php if ($assets['css'] !== null): ?><link rel="stylesheet" href="/build/<?= esc($assets['css'], 'attr') ?>"><?php endif; ?></head>
<body class="fleet-shell"><main class="command-main import-main" id="main-content">
<p><a href="/fleet/vehicles/<?= (int) $vehicle['id'] ?>/wheel-damage/new">Record another wheel occurrence</a></p><h1>Wheel occurrence evidence</h1>
<?php if ($notice !== null): ?><p role="status"><?= esc($notice) ?></p><?php endif; ?>
<?php if ($errors !== []): ?><div role="alert"><ul><?php foreach ($errors as $error): ?><li><?= esc($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<p>Occurrence #<?= (int) $member['id'] ?> · <?= esc(\Config\VehicleDamage::EFFECTS[$member['effect_code']]) ?> · Exact event #<?= (int) $member['condition_event_id'] ?></p><p><?= esc($member['note']) ?></p>
<p><a href="/fleet/vehicles/<?= (int) $vehicle['id'] ?>/wheel-history/<?= (int) $member['vehicle_damage_item_id'] ?>/occurrences/<?= (int) $member['id'] ?>">Occurrence history and frozen comparison</a></p>
<ul><?php foreach ($photos as $photo): ?><li><a href="/fleet/vehicles/<?= (int) $vehicle['id'] ?>/wheel-damage/<?= (int) $member['vehicle_damage_item_id'] ?>/occurrences/<?= (int) $member['id'] ?>/photos/<?= (int) $photo['id'] ?>"><?= esc($photo['label'] ?? 'Occurrence photo') ?> — <?= esc($photo['original_filename']) ?></a></li><?php endforeach; ?></ul>
<?php if (count($photos) === 12): ?><p><a href="?after=<?= (int) $photos[11]['id'] ?>">Next photos</a></p><?php endif; ?>
<?php if ($pending !== null): ?><form method="post" action="/fleet/vehicles/<?= (int) $vehicle['id'] ?>/wheel-damage/<?= (int) $member['vehicle_damage_item_id'] ?>/occurrences/<?= (int) $member['id'] ?>/photos"><?= csrf_field() ?><input type="hidden" name="recover" value="1"><button type="submit">Recover pending save</button></form>
<?php else: ?><form method="post" enctype="multipart/form-data" action="/fleet/vehicles/<?= (int) $vehicle['id'] ?>/wheel-damage/<?= (int) $member['vehicle_damage_item_id'] ?>/occurrences/<?= (int) $member['id'] ?>/photos"><?= csrf_field() ?><input type="hidden" name="command_uuid" value="<?= esc($formData['command_uuid'] ?? $commandUuid, 'attr') ?>">
<?= view('vehicle_wheel_damage/_photos') ?><button type="submit">Attach photos to this occurrence</button><p>Attachment preserves the occurrence event and frozen comparison basis. It does not record another observation.</p></form><?php endif; ?>
</main></body></html>
