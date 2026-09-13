<?php

/** @var list<array<string,mixed>> $receipts */
?>
<section id="attached-evidence" class="section" aria-labelledby="attached-evidence-title">
    <div class="section-heading">
        <div>
            <p class="eyebrow">Receipts</p>
            <h2 id="attached-evidence-title">Attached evidence</h2>
        </div>
        <span class="muted"><?= count($receipts) ?> attached</span>
    </div>
    <?php if ($receipts === []): ?>
        <div class="empty-state"><h3>No receipt attached</h3><p>This expense was recorded without receipt evidence.</p></div>
    <?php else: ?>
        <div class="mapping-list expense-evidence-list">
            <?php foreach ($receipts as $receipt): ?>
                <article class="mapping-card expense-evidence-card">
                    <div class="mapping-card-main">
                        <div>
                            <h3><?= esc((string) ($receipt['file_original_filename'] ?? 'Receipt evidence')) ?></h3>
                            <p><?= esc((string) ($receipt['document_date'] ?? 'Date not captured')) ?> · <?= esc((string) ($receipt['file_mime_type'] ?? 'File type not captured')) ?></p>
                        </div>
                        <a class="secondary-action button-link" href="/operations/expenses/receipts/<?= (int) $receipt['id'] ?>/file">Preview receipt</a>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
