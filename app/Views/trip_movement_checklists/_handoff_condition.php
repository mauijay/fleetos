<?php
/** @var array<string, mixed> $fact */
$evidence = $fact['condition_evidence'];
?>
<div class="handoff-condition">
    <?php if ($evidence === []): ?>
        <p class="movement-fact-detail">No prior observation</p>
    <?php else: ?>
        <?php foreach (['handoff' => 'Confirmed at handoff', 'staging' => 'Last confirmed before handoff'] as $origin => $heading): ?>
            <?php $observations = array_filter($evidence, static fn (array $observation): bool => $observation['origin'] === $origin); ?>
            <?php if ($observations !== []): ?>
                <p class="eyebrow"><?= esc($heading) ?></p>
                <dl class="movement-fact-summary">
                    <?php foreach ($observations as $field => $observation): ?>
                        <div><dt><?= $field === 'cleanliness' ? 'Cleanliness' : esc((string) $fact['energy_label']) ?></dt><dd><?= esc((string) $observation['value_label']) ?><span class="movement-fact-detail"><?= esc((string) $observation['provenance_label']) ?> &middot; <?= esc((string) $observation['source_label']) ?> &middot; <?= esc((string) $observation['actor_label']) ?></span></dd></div>
                    <?php endforeach; ?>
                </dl>
            <?php endif; ?>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
