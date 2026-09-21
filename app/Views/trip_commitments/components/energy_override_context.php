<?php
/** @var array<string,mixed> $commitment */
/** @var bool|null $compact */
$rule = $commitment['energy_rule'] ?? null;
$normalTarget = is_array($rule) && isset($rule['normal_vehicle_target'])
    ? (int) $rule['normal_vehicle_target']
    : null;
?>
<span class="commitment-energy-rule<?= ($compact ?? false) ? ' is-compact' : '' ?>">
    <span><?= esc((string) $commitment['energy_rule_summary']) ?></span>
    <?php if ($normalTarget !== null): ?><small>Normal vehicle target: <?= $normalTarget ?>%</small><?php endif; ?>
    <small>Guest-specific override</small>
</span>
