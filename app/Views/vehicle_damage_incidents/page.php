<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Damage incident | FleetOS</title>
<?php if ($assets['css'] !== null): ?><link rel="stylesheet" href="/build/<?= esc($assets['css'], 'attr') ?>"><?php endif; ?></head>
<body class="fleet-shell"><main class="command-main import-main vehicle-damage-section damage-incident-page" id="main-content">
<p><a href="/fleet/vehicles/<?= (int) $vehicle['id'] ?>#vehicle-damage">Back to <?= esc((string) ($vehicle['fleet_code'] ?? 'vehicle')) ?> condition</a></p>
<?php if ($notice !== null): ?><div class="import-message tone-success"><?= esc($notice) ?></div><?php endif; ?>
<?php if ($errors !== []): ?><div class="import-message tone-danger"><strong>Damage history was not saved.</strong><ul><?php foreach ($errors as $error): ?><li><?= esc($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<?= view('vehicle_damage_incidents/' . $template) ?>
</main><?php if ($assets['js'] !== null): ?><script type="module" src="/build/<?= esc($assets['js'], 'attr') ?>"></script><?php endif; ?></body></html>
