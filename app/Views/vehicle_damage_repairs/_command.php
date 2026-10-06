<?php $retained = (($formData['work_action'] ?? '') === ($workAction ?? '')) ? $formData : []; ?>
<input type="hidden" name="command_key" value="<?= esc((string) ($retained['command_key'] ?? \App\Services\Fleet\VehicleDamageRepairService::commandKey()), 'attr') ?>">
<?php if (isset($job)): ?><input type="hidden" name="expected_version" value="<?= (int) $job['version'] ?>"><?php endif; ?>
<input type="hidden" name="work_action" value="<?= esc($workAction ?? '', 'attr') ?>">
