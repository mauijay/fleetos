# Evidence-backed repair costs (B2.3)

Repair jobs own immutable vendor-side monetary facts in `vehicle_damage_repair_cost_entries`. Each invoice, invoice credit, vendor payment/deposit, or vendor payment refund requires an active, matching, same-job repair document backed by verified private PDF or image bytes. Raw file/image IDs, external references, estimates, and narrative notes do not authorize a monetary source.

## Two separate authorities

Recorded invoiced work cost is the sum of recorded invoices less recorded invoice credits. Without a recorded invoice it is **Unknown**, even when deposits exist. An explicitly verified zero-dollar invoice makes the cost known zero. Before finalization the UI says “Recorded invoiced work cost so far.”

Recorded vendor payments are payments less vendor payment refunds. No payment means “No vendor payments recorded”; fully refunded payments produce known net `0.00`. Deposits may precede invoices, and payments may exceed invoices. An optional invoice reference is informational and does not allocate payments.

Amounts are string-only, normalized by the existing B2.2 money parser, with USD initially supported. Individual facts are bounded by `9999999999.99`; aggregate decimal-string arithmetic has no such row limit and never uses floating point or SQLite `SUM()` as authority. Amount and occurrence date start blank in recording forms.

## Genuine reductions and corrections

A credit must reference an invoice; a refund must reference a payment. The current corrected parent must have matching currency and vendor identity, a date no later than its reduction, and sufficient remaining budget. If both vendor IDs are known they must match; otherwise trimmed Unicode case-folded source snapshots must match exactly. Job vendor mismatch requires an explicit reviewed reason; new source vendor text does not create vendor or maintenance records.

Voiding changes only disposition and its actor, clock, and reason. Facts and source identity stay immutable. Same-kind replacement atomically voids the old recorded head and inserts a linked successor, with one job version/event. Changing kind requires an explicit void and a separate new command. Genuine credit/refund references keep their original IDs and resolve through the unique replacement chain. Cycles, branches, broken chains, missing recorded parents, and incompatible corrections fail closed. Parents with recorded dependent reductions cannot be voided alone. Correcting a parent must preserve every genuine dependent reduction and its budget.

Every document referenced by any cost entry is permanently protected against archive, including references from voided entries. Document ownership remains in B2.2; the cost entry owns `repair_document_id`, with a composite company/job foreign key and no reverse document column.

## Finalization and history

`finalizeRepairCost` explicitly confirms completeness of currently known invoice/credit facts on a completed/cancelled job with retained performed-work history. It requires a current invoiced-state fingerprint, confirmation, a bounded note, and verified sources/relations. The event freezes entry IDs/fingerprints, canonical total, actor, clock, and note. The job stores only the nullable finalization actor/clock/note, never a cached total.

Invoice/credit recording, voiding, replacement, and reopening the same continuing order automatically clear finalization inside the triggering command. That command still writes one version, event, and job audit, preserving prior finalization in its snapshot. Payments/refunds preserve finalization. Explicit `invalidateCostFinalization` requires the current finalization fingerprint and a confirmed reason. Finalization expresses source completeness as presently known; settlement is represented separately by payment facts.

## Commands, transactions, and sources

B2.3 uses a separate action namespace and semantic normalizer; accepted B2.1/B2.2 command hashes remain unchanged. Committed replay returns the stored receipt before stale-state or duplicate candidate checks. Changed payload, actor, or owned context cannot reuse a committed key. Failed validations leave the key reusable. Semantic identity includes money/date/vendor/source descriptor, relationships, expected versions/fingerprints, confirmations, and review reasons, excluding CSRF, temporary paths, uploaded object identity, and recording clocks.

Material B2.3 commands require the aggregate runner to own the outer transaction. Shared lock order is vehicles, jobs, conditions, memberships, estimates, estimate scope, repair documents, cost entries, files, images, then event/audit writes; IDs ascend within each table. Upload checksum candidates are chosen before lower-order locks and only that candidate is revalidated under metadata locks. Source jobs and alternative candidates are never discovered late.

Metadata locks use the current document rows returned by the earlier ordered lock. The B2.2 archive guard re-reads already locked cost rows using a current locking read, including when a caller-owned repeatable-read transaction has an older snapshot. MariaDB contention gates observe an actual InnoDB wait and resume the original command after the canonical winner commits, without rolling back or retrying the waiter.

Owned B2.3 MariaDB transactions use one-shot READ COMMITTED isolation with the same vehicle/job/row locks. Each statement sees committed state after a metadata wait, including cross-job duplicate warnings, and range locks do not block neighboring aggregates unnecessarily. The session default and earlier B2.1/B2.2 commands retain their isolation behavior. A cross-vehicle shared-binary contention gate proves the newly committed candidate invalidates the old duplicate preview and leaves the waiting job unchanged.

New binary, document, entry, event, and audits commit together. Proven pre-commit rollback removes only newly created uncommitted bytes, preserving reused content. Uncertain commit outcomes preserve bytes and require retry with the **same command key and frozen descriptor**. The permanent lost-acknowledgement gate throws after actual COMMIT, verifies state independently, and proves replay produces no duplicate file/document/entry/event/audit/version.

An uncertain B2.3 result provides a frozen retry payload containing no upload object or temporary path. The controller attempts one recovery with that original key and semantic state before redirecting. A committed receipt can be recovered without re-uploading. If recovery remains unavailable, the session retains that exact payload for the company, vehicle, job and actor across page reloads. The job shows a retry button instead of new monetary commands until the original receipt is recovered; posted edits cannot replace the pending command.

Readonly bounded duplicate candidates compare repair costs, operating expenses/receipts, and maintenance indicators: amount/date, vendor, reference, and checksum when available. Candidates are warnings requiring explicit review, fingerprint, and reason, never automatic identity, merge, deletion, or another-domain mutation. A POST preview moves no binary; an upload must be reselected with the same frozen descriptor when saving.

## Financial and recovery boundary

B2.3 does not write operating expenses, maintenance, claims, recoveries, financial reporting, movements, commitments, availability, physical condition, incident attribution, estimates/scope, or accepted estimate selection. An accepted estimate may be displayed for comparison but never prefills or controls actual monetary facts. There are no recovery tables, allocations, claim offsets, loss calculations, or net host cost. Recovery and reconciliation remain B3 work.

## Migration and verification

App migration `2026-10-06-000032_CreateVehicleDamageRepairCosts` is additive: one 20-column table, exactly three nullable job finalization fields, and document context uniqueness. Explicit named checks avoid Forge raw-constraint overwrite. Company, job, document, related/replacement, and optional vendor foreign keys restrict update/delete. Immutable-update and no-delete guards preserve monetary history. Existing jobs receive NULL finalization; no historical costs are inferred. Readiness checks detect incomplete schema, including the source archive guard. MariaDB DDL is not assumed atomic; inspect partial schema/ledger before recovery. `down()` refuses destructive rollback. Accepted migration 000031 is unchanged.

The supported prefix upgrade builds the actual accepted baseline before renaming isolated tables, retaining historical constraints. It does not rewrite the unrelated historical long-FK-name limitation. Local verification uses only synthetic disposable SQLite/MariaDB databases, loopback browser fixtures, and ignored build artifacts. Local implementation authorizes no commit, release metadata, publication, deployment, or production access.
