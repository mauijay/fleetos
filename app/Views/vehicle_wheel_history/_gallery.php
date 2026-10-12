<section aria-label="<?= esc($heading,'attr') ?>"><h3><?= esc($heading) ?></h3>
<?php if($gallery['rows']===[]): ?><p>No evidence recorded in this gallery.</p><?php endif; ?>
<div class="wheel-photo-grid"><?php foreach($gallery['rows'] as $photo): ?><article class="wheel-photo">
<p><strong><?= esc($photo['role']??$photo['label']??'Recorded evidence') ?></strong> · <?= esc((string)($photo['original_filename']??'Filename not recorded')) ?></p>
<?php if($photo['available']): ?><a href="<?= esc($photo['url'],'attr') ?>"><img loading="lazy" src="<?= esc($photo['url'],'attr') ?>" alt="<?= esc(($photo['role']??'Wheel evidence').' — '.($photo['original_filename']??'recorded photo'),'attr') ?>"></a><?php else: ?><p>Evidence unavailable</p><?php endif; ?>
<p>Evidence recorded <?= esc((string)$photo['created_at']) ?></p><p class="wheel-muted">Uploaded <?= esc((string)($photo['uploaded_at']??'time not recorded')) ?>. Upload time is not capture time.</p>
<?php if(($occurrenceRecordedAt??null)!==null && $photo['created_at']>$occurrenceRecordedAt): ?><p>Uploaded/recorded later than this occurrence.</p><?php endif; ?>
</article><?php endforeach; ?></div>
<?php if($gallery['has_next']): ?><a href="<?= esc($pageUrl([($cursorKey??'after')=>$gallery['next_after']]),'attr') ?>">Next photos</a><?php endif; ?></section>
