<?php
/** @var array<string,mixed> $commitment */
/** @var bool|null $compact */
$normalPolicySummary = $commitment['normal_vehicle_policy_summary'] ?? null;
?>
<span class="commitment-energy-rule<?= ($compact ?? false) ? ' is-compact' : '' ?>">
    <span><?= esc((string) $commitment['energy_rule_summary']) ?></span>
    <?php if ($normalPolicySummary !== null): ?><small><?= esc((string) $normalPolicySummary) ?></small><?php endif; ?>
    <small>Guest-specific override</small>
</span>
