<?php $financial = $financialReconciliation ?? ['ready' => false]; ?>
<section id="financial-reconciliation" class="damage-costs" aria-labelledby="financial-reconciliation-title">
<h2 id="financial-reconciliation-title">Financial Reconciliation</h2>
<p>Operating expenses remain the financial report authority. Linking the same complete cost preserves both histories and leaves report totals unchanged.</p>
<?php if (! empty($financialRetry)): ?><p role="status">A save outcome needs verification. Its original command key and source fingerprints are retained.</p><form method="post" action="<?= $base ?>/financial-reconciliations"><?= csrf_field() ?><button type="submit">Retry original reconciliation command</button></form><?php endif; ?>
<?php if (! $financial['ready']): ?><p>Expense reconciliation is unavailable until its schema is ready.</p><?php else: ?>
<?php if ($financial['families'] === []): ?><p>No eligible invoice family or expense recorded.</p><?php endif; ?>
<?php foreach ($financial['families'] as $root => $family): ?>
<h3>Invoice family <?= (int) $root ?></h3>
<?php if ($family['snapshot'] === null): ?><p role="status"><?= esc($family['issue']) ?></p><?php else: $cost = $family['snapshot']; $active = null; foreach ($financial['history'] as $row) { if ((int) $row['cost_root_entry_id'] === (int) $root && $row['status_code'] === 'active') { $active = $row; } } ?>
<p>Damage Ledger current net: USD <?= esc($cost['amount']) ?> &middot; <?= esc($cost['occurred_on']) ?> &middot; <?= esc($cost['vendor']) ?></p>
<p><?= $active === null ? 'Unreconciled' : esc($active['read_status']) ?></p>
<?php if (! $financial['finalized']): ?><p>Cost finalization is required before creating or replacing a reconciliation.</p><?php endif; ?>
<?php if ($financial['candidate_ids'] === []): ?><p>No current vehicle expense candidates. Reconciliation does not create an expense.</p><?php endif; ?>
<ul><?php foreach ($financial['candidate_ids'] as $expenseId): $source = $financial['expenses'][$expenseId]['snapshot']; ?>
<li>Expense <?= (int) $expenseId ?>: USD <?= esc($source['amount']) ?> &middot; <?= esc($source['expense_date']) ?> &middot; <?= esc((string) $source['vendor']) ?>
<p><?= $cost['amount'] === $source['amount'] ? 'Exact amount candidate' : 'Related / unreconciled: amount differs' ?><?= $cost['occurred_on'] !== $source['expense_date'] ? ' · Date explanation required' : '' ?><?= (string) $cost['vendor'] !== (string) $source['vendor'] || (string) $cost['reference'] !== (string) $source['payment_reference'] ? ' · Descriptor review required' : '' ?></p>
<?php $reservedBy = $financial['reserved_expenses'][$expenseId] ?? null; $reservedElsewhere = $reservedBy !== null && (int) $reservedBy !== (int) ($active['id'] ?? 0); ?>
<?php if ($reservedElsewhere): ?><p>Already actively reconciled. A second complete cost cannot reserve this expense.</p><?php endif; ?>
<?php if (! $reservedElsewhere && $financial['finalized'] && $cost['amount'] === $source['amount']): ?><a href="<?= $base ?>/financial-reconciliations/preview?cost_root_entry_id=<?= (int) $root ?>&amp;operating_expense_id=<?= (int) $expenseId ?><?= $active === null ? '' : '&amp;reconciliation_id=' . (int) $active['id'] ?>">Preview <?= $active === null ? 'complete cost reconciliation' : 'replacement' ?></a><?php endif; ?>
</li><?php endforeach; ?></ul>
<?php endif; endforeach; ?>
<h3>Retained reconciliation history</h3>
<ol class="damage-history"><?php foreach ($financial['history'] as $row): ?>
<li><strong>Reconciliation <?= (int) $row['id'] ?> &middot; <?= esc($row['read_status']) ?></strong>
<p>Invoice root <?= (int) $row['cost_root_entry_id'] ?> ↔ <a href="/operations/expenses/<?= (int) $row['operating_expense_id'] ?>">Expense <?= (int) $row['operating_expense_id'] ?></a>: USD <?= esc($row['amount_snapshot']) ?></p>
<p>Invoice date <?= esc($row['damage_occurred_on']) ?>; expense report date <?= esc($row['financial_occurred_on']) ?>. <?= esc((string) $row['date_difference_reason']) ?></p>
<p><?= esc($row['reason']) ?></p>
<?php if ($row['replacement_of_reconciliation_id'] !== null): ?><p>Replaces reconciliation <?= (int) $row['replacement_of_reconciliation_id'] ?>.</p><?php endif; ?>
<details><summary>Frozen provenance</summary><p>Fingerprint version <?= esc($row['fingerprint_version']) ?>. Expense reporting uses its existing date and implicit USD basis.</p><p>Cost digest <?= esc($row['damage_lineage_fingerprint']) ?></p><p>Expense digest <?= esc($row['financial_source_fingerprint']) ?></p></details>
<?php if ($row['status_code'] === 'active'): ?><details><summary>Invalidate reconciliation</summary><form method="post" action="<?= $base ?>/financial-reconciliations/invalidate"><?= csrf_field() ?><?= view('vehicle_damage_repairs/_command', ['workAction' => 'b32_invalidate']) ?><input type="hidden" name="reconciliation_id" value="<?= (int) $row['id'] ?>"><input type="hidden" name="expected_reconciliation_state" value="<?= esc($row['state'], 'attr') ?>"><label>Reason<textarea name="reason" maxlength="2000" required></textarea></label><label><input type="checkbox" name="confirmed" value="1" required> Retain this history and release its current reservations.</label><button type="submit">Invalidate reconciliation</button></form></details>
<?php else: ?><p>Invalidated <?= esc($row['invalidated_at']) ?>: <?= esc($row['invalidation_reason']) ?>. A new explicit reconciliation is required.</p><?php endif; ?>
</li><?php endforeach; ?></ol>
<?php endif; ?></section>
