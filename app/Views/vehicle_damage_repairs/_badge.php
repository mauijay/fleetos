<?php
$jobMap = array_column($work['jobs'] ?? [], null, 'id');
$matches = [];
foreach ($work['members'] ?? [] as $membership) {
    if ((int) $membership['vehicle_damage_item_id'] === (int) $conditionId && isset($jobMap[$membership['vehicle_damage_repair_job_id']])) {
        $job = $jobMap[$membership['vehicle_damage_repair_job_id']];
        $job['membership_withdrawn'] = $membership['withdrawn_at'] !== null;
        $matches[$membership['vehicle_damage_repair_job_id']] = $job;
    }
}
$activeWork = array_filter($matches, static fn (array $j): bool => ! $j['membership_withdrawn'] && ! in_array($j['status_code'], ['completed', 'cancelled'], true));
uasort($matches, static fn (array $a, array $b): int => [$a['updated_at'], (int) $a['id']] <=> [$b['updated_at'], (int) $b['id']]);
?>
<?php if ($matches !== []): $latest = end($matches); ?><p class="muted work-badge"><?= count($activeWork) ?> active · <?= count($matches) - count($activeWork) ?> past work job(s) · Latest: <?= esc(\Config\VehicleDamage::WORK_STATUSES[$latest['status_code']]) ?> · <a href="/fleet/vehicles/<?= (int) $vehicleId ?>/damage-repairs/<?= (int) $latest['id'] ?>">Work history</a></p><?php endif; ?>
