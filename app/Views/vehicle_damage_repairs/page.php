<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Work &amp; Repair | FleetOS</title>
<?php if ($assets['css'] !== null): ?><link rel="stylesheet" href="/build/<?= esc($assets['css'], 'attr') ?>"><?php endif; ?></head>
<body class="fleet-shell"><main class="command-main import-main vehicle-damage-section damage-incident-page work-page" id="main-content">
<p><a href="/fleet/vehicles/<?= (int) $vehicle['id'] ?>#vehicle-damage">Back to vehicle condition</a> · <a href="/fleet/vehicles/<?= (int) $vehicle['id'] ?>/damage-repairs">Work &amp; Repair</a></p>
<?php if ($notice !== null): ?><div class="import-message tone-success" role="status"><?= esc($notice) ?></div><?php endif; ?>
<?php if ($errors !== []): ?><div class="import-message tone-danger" role="alert"><strong>Work was not saved.</strong><ul><?php foreach ($errors as $error): ?><li><?= esc($error) ?></li><?php endforeach; ?></ul><p>Review the current state and explicitly confirm again.</p></div><?php endif; ?>
<?= view('vehicle_damage_repairs/' . $template) ?></main>
<?php if ($assets['js'] !== null): ?><script type="module" src="/build/<?= esc($assets['js'], 'attr') ?>"></script><?php endif; ?></body></html>
