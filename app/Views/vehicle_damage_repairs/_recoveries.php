<?php $recovery = $repairRecoveries ?? ['ready' => false]; ?>
<section id="repair-recoveries" aria-labelledby="repair-recoveries-title">
<h2 id="repair-recoveries-title">Recoveries and repair balance</h2>
<p>Turo recovery recognition is unavailable. Record only documented payments received outside Turo. Recovery recording does not change fleet financial reports.</p>
<?php if (! empty($recoveryRetry)): ?><p role="status">A recovery save outcome needs verification. Retry its original command and evidence identity.</p><form action="<?= $base ?>/recoveries" method="post"><?= csrf_field() ?><button type="submit">Retry original recovery command</button></form>
<?php elseif (! $recovery['ready']): ?><p>Recovery recording is unavailable until its schema is ready.</p>
<?php else: ?>
<dl class="work-facts">
<div><dt>Repair cost</dt><dd><?= $recovery['cost'] === null ? 'Unknown' : ($recovery['cost_finalized_valid'] ? 'Final recorded invoiced work cost: USD ' : 'Recorded invoiced work cost so far: USD ') . esc($recovery['cost']) ?></dd></div>
<div><dt>Recorded net recovery</dt><dd><?= $recovery['net'] === null ? ($recovery['ledger_state'] !== null ? 'No recovery recorded' : 'Source review required') : 'USD ' . esc($recovery['net']) ?></dd></div>
<div><dt>Recovery completeness</dt><dd><?= $recovery['recovery_finalized_valid'] ? 'Finalized' : ($job['recovery_finalized_at'] === null ? 'Not finalized' : 'Review required') ?></dd></div>
<div><dt>Host-borne repair cost</dt><dd><?= $recovery['host_balance'] === null ? 'Unknown' : 'Final host-borne repair balance: USD ' . esc($recovery['host_balance']) ?></dd></div>
</dl>
<p>Accepted estimates are proposals. Vendor payments and refunds are settlement facts; neither establishes recovery or final host cost.</p>
<?php if ($recovery['excess'] !== null): ?><p role="status">Recovery exceeds recorded repair cost by USD <?= esc($recovery['excess']) ?> &mdash; review recovery scope.</p><?php endif; ?>
<?php foreach ($recovery['issues'] as $issue): ?><p role="status"><?= esc($issue) ?></p><?php endforeach; ?>
<?php if ($job['recovery_finalized_at'] !== null): ?><p>Recovery completeness recorded <?= esc($job['recovery_finalized_at']) ?> by operator <?= (int) $job['recovery_finalized_by'] ?>.</p><p><?= nl2br(esc($job['recovery_finalization_note'])) ?></p><?php endif; ?>
<p><a class="button" href="<?= $base ?>/recoveries/new">Record recovery / reversal</a></p>
<ol class="damage-history">
<?php foreach ($recovery['entries'] as $entry): ?>
<li><strong><?= esc(\Config\VehicleDamageRepairRecoveries::KINDS[$entry['kind_code']] ?? 'Recovery source') ?> <?= (int) $entry['id'] ?></strong> &middot; <?= esc($entry['status_code']) ?>
<p><?= $entry['authority_code'] === 'external_receipt' ? 'USD ' . esc($entry['amount']) . ' / ' . esc($entry['occurred_on']) : 'Turo source review required; recognition unavailable' ?> &middot; <?= esc($entry['payer_snapshot']) ?></p>
<p><?= esc(\Config\VehicleDamageRepairRecoveries::SOURCES[$entry['source_type']] ?? 'Unknown source') ?> &middot; Reference <?= esc($entry['source_reference']) ?></p>
<p>Source account: <?= esc($entry['source_namespace']) ?></p>
<?php $sourceSnapshot = json_decode($entry['source_snapshot'], true); if (is_string($sourceSnapshot['details'] ?? null)): ?><p><?= nl2br(esc($sourceSnapshot['details'])) ?></p><?php endif; ?>
<?php if ($entry['damage_claim_id'] !== null): ?><p>Claim context <?= (int) $entry['damage_claim_id'] ?> (association only).</p><?php endif; ?>
<?php if ($entry['related_recovery_entry_id'] !== null): ?><p>Original receipt: <?= (int) $entry['related_recovery_entry_id'] ?></p><?php endif; ?>
<?php if ($entry['replacement_of_recovery_entry_id'] !== null): ?><p>Correction replaces recovery <?= (int) $entry['replacement_of_recovery_entry_id'] ?>. Original references and evidence remain retained.</p><?php endif; ?>
<?php if ($entry['note'] !== null): ?><p><?= nl2br(esc($entry['note'])) ?></p><?php endif; ?>
<p><a href="<?= $base ?>/documents/<?= (int) $entry['repair_document_id'] ?>/download">Private recovery evidence <?= (int) $entry['repair_document_id'] ?></a></p>
<?php if ($entry['status_code'] === 'voided'): ?><p>Voided <?= esc($entry['voided_at']) ?>: <?= nl2br(esc($entry['void_reason'])) ?></p>
<?php elseif (isset($recovery['entry_states'][$entry['id']])): ?>
<p><a href="<?= $base ?>/recoveries/<?= (int) $entry['id'] ?>/replacement">Correct with a same-source replacement</a></p>
<details class="capital-disclosure"><summary>Void erroneous recovery</summary><form action="<?= $base ?>/recoveries/<?= (int) $entry['id'] ?>/void" method="post"><?= csrf_field() ?><?= view('vehicle_damage_repairs/_command', ['workAction' => 'b31_void_' . $entry['id']]) ?><input type="hidden" name="expected_entry_state" value="<?= esc($recovery['entry_states'][$entry['id']], 'attr') ?>"><label>Correction reason<textarea name="reason" required maxlength="2000"></textarea></label><label><input type="checkbox" name="confirmed" value="1" required> This corrects an erroneous assertion. Money returned must be recorded as a reversal.</label><button type="submit">Void recovery</button></form></details>
<?php endif; ?></li><?php endforeach; ?></ol>
<?php if ($job['recovery_finalized_at'] === null && $recovery['ledger_state'] !== null): ?>
<details class="capital-disclosure"><summary>Finalize recovery completeness</summary><form action="<?= $base ?>/recoveries/finalize" method="post"><?= csrf_field() ?><?= view('vehicle_damage_repairs/_command', ['workAction' => 'b31_finalize']) ?><input type="hidden" name="expected_ledger_state" value="<?= esc($recovery['ledger_state'], 'attr') ?>">
<?php if ($recovery['duplicate_review']['candidates'] !== []): ?><p>Possible duplicate sources require evidence review; matching amounts, dates or payer names do not prove identity.</p><ul><?php foreach ($recovery['duplicate_review']['candidates'] as $review): ?><li>Recovery <?= (int) $review['entry_id'] ?>: <?php foreach ($review['review']['candidates'] as $candidate): ?>job <?= (int) $candidate['vehicle_damage_repair_job_id'] ?> / recovery <?= (int) $candidate['id'] ?>; <?php endforeach; ?></li><?php endforeach; ?></ul><input type="hidden" name="duplicate_review_fingerprint" value="<?= esc($recovery['duplicate_review']['fingerprint'], 'attr') ?>"><label><input type="checkbox" name="duplicate_review_confirmed" value="1" required> I verified these are distinct documented payments.</label><label>Duplicate review reason<textarea name="duplicate_review_reason" maxlength="2000" required></textarea></label><?php endif; ?>
<label>Completeness note<textarea name="note" required maxlength="2000"></textarea></label>
<?php if ($recovery['net'] === null): ?><label><input type="checkbox" name="no_recovery_confirmed" value="1" required> No recovery is currently known for this job.</label><?php endif; ?>
<label><input type="checkbox" name="scope_review_confirmed" value="1"> I reviewed recovery scope, including any amount exceeding the recorded repair cost. The receipt belongs wholly to this job.</label>
<label><input type="checkbox" name="completeness_confirmed" value="1" required> All known receipts and genuine reversals are recorded; no unresolved duplicate or allocation ambiguity remains.</label>
<label><input type="checkbox" name="confirmed" value="1" required> Finalize recovery completeness independently of repair cost.</label><button type="submit">Finalize recovery</button></form></details>
<?php elseif ($job['recovery_finalized_at'] !== null && $recovery['finalization_state'] !== null): ?>
<details class="capital-disclosure"><summary>Invalidate recovery completeness</summary><form action="<?= $base ?>/recoveries/invalidate" method="post"><?= csrf_field() ?><?= view('vehicle_damage_repairs/_command', ['workAction' => 'b31_invalidate']) ?><input type="hidden" name="expected_finalization_state" value="<?= esc($recovery['finalization_state'], 'attr') ?>"><label>Review reason<textarea name="reason" required maxlength="2000"></textarea></label><label><input type="checkbox" name="confirmed" value="1" required> Recovery completeness needs review. Retain every monetary fact.</label><button type="submit">Invalidate recovery finalization</button></form></details>
<?php endif; endif; ?>
</section>
