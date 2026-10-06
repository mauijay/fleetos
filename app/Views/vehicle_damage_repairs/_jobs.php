<?php foreach (['Active work' => ['planned', 'scheduled', 'in_progress', 'deferred'], 'Completed / cancelled work' => ['completed', 'cancelled']] as $title => $states): ?>
<h3><?= esc($title) ?></h3><ul class="damage-history"><?php $found = false; foreach ($work['jobs'] ?? [] as $record): if (! in_array($record['status_code'], $states, true)) { continue; } $found = true; ?>
<li><a href="/fleet/vehicles/<?= (int) $vehicleId ?>/damage-repairs/<?= (int) $record['id'] ?>"><?= esc($record['summary']) ?></a> · <?= esc(\Config\VehicleDamage::WORK_INTENTS[$record['intent_code']]) ?> · <?= esc(\Config\VehicleDamage::WORK_STATUSES[$record['status_code']]) ?></li>
<?php endforeach; if (! $found): ?><li class="muted">No recorded work in this group.</li><?php endif; ?></ul><?php endforeach; ?>
