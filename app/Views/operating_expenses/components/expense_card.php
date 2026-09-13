<?php

/** @var array<string,mixed> $expense */
/** @var callable(string):string $statusLabel */
/** @var string $vehicleGroup */
$receiptCount = (int) ($expense['receipt_count'] ?? 0);
$firstReceiptId = (int) ($expense['first_receipt_id'] ?? 0);
?>
<article class="mapping-card expense-card">
    <div class="mapping-card-main">
        <div>
            <span class="status-badge <?= $expense['status_code'] === 'archived' ? 'tone-neutral' : 'tone-success' ?>"><?= esc($statusLabel((string) $expense['status_code'])) ?></span>
            <h3>$<?= esc((string) $expense['amount']) ?> · <?= esc((string) $expense['category_name']) ?></h3>
            <p><?= esc((string) $expense['expense_date']) ?> · <?= esc((string) ($expense['vendor'] ?? 'Vendor not captured')) ?></p>
        </div>
        <a class="secondary-action button-link" href="/operations/expenses/<?= (int) $expense['id'] ?>">Review</a>
    </div>
    <dl class="issue-facts">
        <div><dt>Scope</dt><dd><?= esc($vehicleGroup) ?></dd></div>
        <div><dt>Source</dt><dd><?= esc($statusLabel((string) $expense['source_code'])) ?></dd></div>
        <div>
            <dt>Receipt</dt>
            <dd class="receipt-fact-value">
                <?php if ($receiptCount === 0): ?>
                    None
                <?php else: ?>
                    <span><?= $receiptCount ?> attached</span>
                    <?php if ($receiptCount === 1 && $firstReceiptId > 0): ?>
                        <a class="text-link receipt-preview-link" href="/operations/expenses/receipts/<?= $firstReceiptId ?>/file">View receipt</a>
                    <?php elseif ($receiptCount > 1): ?>
                        <a class="text-link receipt-preview-link" href="/operations/expenses/<?= (int) $expense['id'] ?>#attached-evidence">View receipts</a>
                    <?php endif; ?>
                <?php endif; ?>
            </dd>
        </div>
    </dl>
</article>
