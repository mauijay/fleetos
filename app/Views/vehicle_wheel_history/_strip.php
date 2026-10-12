<style>.wheel-strip{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin:18px 0}.wheel-cell{display:flex;flex-direction:column;gap:6px;min-width:0;min-height:44px;padding:14px;border:1px solid #64748b;border-radius:10px;overflow-wrap:anywhere;text-decoration:none}.wheel-cell:focus-visible,.wheel-history a:focus-visible{outline:3px solid #2563eb;outline-offset:3px}.wheel-cell-conflict{border:2px solid #b91c1c}.wheel-cell small{display:block}@media(max-width:600px){.wheel-strip{grid-template-columns:repeat(2,minmax(0,1fr))}}</style>
<section aria-label="Wheel curb rash status">
<h2>Wheel curb rash</h2>
<div class="wheel-strip">
<?php foreach ($projection['wheels'] as $wheel): ?>
<a class="wheel-cell<?= $wheel['state']==='conflict'?' wheel-cell-conflict':'' ?>" href="<?= esc($wheel['history_url'],'attr') ?>" aria-label="<?= esc($wheel['label'].': '.$wheel['status'].'. View wheel history','attr') ?>">
<strong><?= esc($wheel['wheel_code']) ?> · <?= esc($wheel['label']) ?></strong><span><?= esc($wheel['status']) ?></span>
<?php if($wheel['trip_badge']!==null): ?><small><?= esc($wheel['trip_badge']) ?><?= $wheel['trip_badge_noncausal']?' — noncausal observation':'' ?></small><?php endif; ?>
<?php $repair=$wheel['repair_context']; if($repair!==null && $repair['physical_event_code']==='repaired' && $wheel['active_condition_id']===null): ?><small>Repaired <?= esc((string)$repair['physical_occurred_at']) ?></small><?php elseif($repair!==null && $repair['work_membership_id']!==null && $repair['withdrawn_at']===null): ?><small>Work result: <?= esc(str_replace('_',' ',(string)$repair['result_code'])) ?></small><?php endif; ?>
<small>View history</small></a>
<?php endforeach; ?></div>
<?php if($projection['unknown']['count']>0): ?><p class="wheel-unknown"><a href="<?= esc($projection['unknown']['url'],'attr') ?>">Wheel position unknown · <?= (int)$projection['unknown']['count'] ?> recorded conditions</a></p><?php endif; ?>
<?php if($projection['conflict_count']>0): ?><p role="status">Review conflicting records. No condition has been selected as current truth for the affected wheels.</p><?php endif; ?>
</section>
