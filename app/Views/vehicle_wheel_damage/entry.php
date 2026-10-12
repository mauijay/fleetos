<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Wheel curb rash | FleetOS</title>
<?php if ($assets['css'] !== null): ?><link rel="stylesheet" href="/build/<?= esc($assets['css'], 'attr') ?>"><?php endif; ?>
<style>.wheel-entry{max-width:52rem;margin:auto;padding:1rem}.wheel-entry form{display:grid;gap:1rem}.wheel-entry input,.wheel-entry select,.wheel-entry textarea{box-sizing:border-box;width:100%;max-width:100%;min-height:44px}.wheel-entry textarea{min-height:8rem}.wheel-selectors,.wheel-actions{display:flex;flex-wrap:wrap;gap:.75rem}.wheel-entry button{min-height:44px;min-width:44px;padding:.75rem}.wheel-entry fieldset{min-width:0}.wheel-entry p,.wheel-entry li{overflow-wrap:anywhere}.wheel-entry :focus-visible{outline:3px solid #2563eb;outline-offset:3px}</style></head>
<body class="fleet-shell"><main id="main-content" class="wheel-entry">
<p><a href="/fleet/vehicles/<?= (int) $vehicle['id'] ?>#vehicle-damage">Back to vehicle condition</a></p>
<h1>Wheel curb rash</h1>
<?php if ($notice !== null): ?><p role="status"><?= esc($notice) ?></p><?php endif; ?>
<?php if ($errors !== []): ?><div id="wheel-errors" role="alert" tabindex="-1" autofocus><strong>Wheel occurrence was not saved.</strong><ul><?php foreach ($errors as $error): ?><li><?= esc($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<?php if ($pending !== null): ?>
<?php $pendingPath = str_starts_with($pending['action'], 'photos:') ? '/wheel-damage/' . str_replace(':', '/occurrences/', substr($pending['action'], 7)) . '/photos' : '/wheel-damage/' . $pending['action']; ?>
<form method="post" action="/fleet/vehicles/<?= (int) $vehicle['id'] ?><?= esc($pendingPath, 'attr') ?>"><?= csrf_field() ?><input type="hidden" name="recover" value="1"><p>The previous save needs receipt verification before another command.</p><button type="submit">Recover pending save</button></form>
<?php else: ?>
<form method="get" action="/fleet/vehicles/<?= (int) $vehicle['id'] ?>/wheel-damage/new">
<fieldset><legend>Select wheel (left/right facing forward)</legend><div class="wheel-selectors">
<?php foreach ($wheels as $code => $choice): ?><button type="submit" name="wheel_code" value="<?= esc($code, 'attr') ?>" aria-label="<?= esc($choice['label'], 'attr') ?>" aria-pressed="<?= $wheel_code === $code ? 'true' : 'false' ?>"><?= esc($code) ?></button><?php endforeach; ?>
</div></fieldset></form>
<h2><?= esc($wheels[$wheel_code]['label']) ?></h2>
<?php if ($state['state'] === 'conflict' || $state['alias_integrity'] !== 'valid'): ?><p role="alert">Wheel history needs review before recording an occurrence.</p>
<?php else: ?>
<?php if ($condition !== null): ?><p>Current condition #<?= (int) $condition['id'] ?>: <?= esc($condition['description']) ?> — <?= esc(\App\Services\Fleet\VehicleDamageService::SEVERITIES[$condition['severity_code']]) ?></p><?php endif; ?>
<p id="clock-help">Clock positions are referenced with the valve stem at 12 o'clock.</p>
<p id="clock-example">Example: Deep 4-inch rash at 2 o'clock; light 3-inch scuff at 8 o'clock.</p>
<?php $value = static fn (string $key, mixed $default = ''): mixed => $formData[$key] ?? $default; $timeValue = static fn (mixed $time): string => str_replace(' ', 'T', substr((string) $time, 0, 16)); ?>
<form id="wheel-occurrence-form" method="post" enctype="multipart/form-data" action="/fleet/vehicles/<?= (int) $vehicle['id'] ?>/wheel-damage/<?= $condition === null ? 'new' : 'worsening' ?>" aria-describedby="<?= $errors !== [] ? 'wheel-errors ' : '' ?>clock-help">
<?= csrf_field() ?><input type="hidden" name="command_uuid" value="<?= esc($value('command_uuid', $commandUuid), 'attr') ?>">
<input type="hidden" name="wheel_code" value="<?= esc($wheel_code, 'attr') ?>">
<input type="hidden" name="expected_wheel_state" value="<?= esc($state['fingerprint'], 'attr') ?>">
<?php if ($condition !== null): ?><input type="hidden" name="condition_id" value="<?= (int) $condition['id'] ?>"><input type="hidden" name="expected_condition_state" value="<?= esc($state['candidates'][0]['fingerprint'], 'attr') ?>"><?php endif; ?>
<label for="wheel-note"><?= $condition === null ? 'Describe the new curb rash' : 'Describe the worsening delta or unchanged observation' ?></label><textarea id="wheel-note" name="note" maxlength="2000" required aria-describedby="clock-help clock-example<?= $errors !== [] ? ' wheel-errors' : '' ?>"><?= esc($value('note')) ?></textarea>
<label for="wheel-severity">Severity after this report</label><select id="wheel-severity" name="severity_code"><?php foreach (\App\Services\Fleet\VehicleDamageService::SEVERITIES as $code => $label): ?><option value="<?= esc($code, 'attr') ?>" <?= $value('severity_code', $condition['severity_code'] ?? 'cosmetic') === $code ? 'selected' : '' ?>><?= esc($label) ?></option><?php endforeach; ?></select>
<label for="wheel-discovered">Discovered at (Honolulu)</label><input id="wheel-discovered" type="datetime-local" name="discovered_at" value="<?= esc($timeValue($value('discovered_at', date('Y-m-d H:i:s'))), 'attr') ?>" required>
<label for="wheel-occurred">Known occurrence time (optional; leave blank when unknown)</label><input id="wheel-occurred" type="datetime-local" name="occurred_at" value="<?= esc($timeValue($value('occurred_at')), 'attr') ?>">
<?php if ($condition !== null): ?><label for="wheel-observed">Observation time for Confirm unchanged (Honolulu)</label><input id="wheel-observed" type="datetime-local" name="observed_at" value="<?= esc($timeValue($value('observed_at', date('Y-m-d H:i:s'))), 'attr') ?>"><?php endif; ?>
<label for="wheel-trip">Associated trip (optional; association does not establish cause)</label><select id="wheel-trip" name="trip_id"><option value="">No trip context</option><?php foreach ($trips as $trip): ?><option value="<?= (int) $trip['id'] ?>" <?= (string) $value('trip_id') === (string) $trip['id'] ? 'selected' : '' ?>><?= esc($trip['turo_reservation_id']) ?></option><?php endforeach; ?></select>
<label for="wheel-attribution">Attribution</label><select id="wheel-attribution" name="attribution_type"><?php foreach (\Config\VehicleDamage::ATTRIBUTIONS as $code => $label): ?><option value="<?= esc($code, 'attr') ?>" <?= $value('attribution_type', 'unknown') === $code ? 'selected' : '' ?>><?= esc($label) ?></option><?php endforeach; ?></select>
<label for="wheel-reason">Supporting reason (required when attributing or suspecting cause)</label><textarea id="wheel-reason" name="reason" maxlength="2000"><?= esc($value('reason')) ?></textarea>
<?= view('vehicle_wheel_damage/_photos') ?>
<div class="wheel-actions"><?php if ($condition === null): ?><button type="submit">New curb rash</button><?php else: ?><button type="submit">Record worsening</button><button type="submit" formaction="/fleet/vehicles/<?= (int) $vehicle['id'] ?>/wheel-damage/unchanged">Confirm unchanged</button><?php endif; ?></div>
<?php if ($condition !== null): ?><p>Confirm unchanged records an observation and does not assign causal responsibility. Keep severity unchanged and leave occurrence time blank for this action.</p><?php endif; ?>
</form><?php endif; ?><?php endif; ?>
</main></body></html>
