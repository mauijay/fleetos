# Repair recoveries (B3.1)

B3.1 records actual third-party money received for one repair/mitigation job, genuine reversals, and recovery completeness. It consumes B2.3 invoices minus invoice credits read-only. Vendor payments/refunds remain separate settlement facts. Estimates and claim workflow amounts never establish actual cost or recovery.

## Authority and source identity

`vehicle_damage_repair_recovery_entries` owns external receipts. Required facts are positive canonical USD amount, actual nonfuture receipt/reversal date, payer snapshot, stable payment namespace/reference, provenance/scope description, and matching private binary evidence. Operators explicitly confirm actual receipt/return, whole-job attribution, receipt outside Turo, and absence of another recovery or B2.3 credit/refund representation.

Supported external categories: guest direct, insurance, vendor compensation, other documented third-party recovery. Vendor invoice credits and returned vendor payments belong to B2.3. Approval, estimate, promise, narrative-only receipt, and mixed-purpose/multi-job payment are rejected.

Namespaces are `bank_transfer:<opaque-account-token>`, `check:<opaque-account-token>`, `cash_receipt:<opaque-account-token>`, or `payment_processor:<opaque-account-token>`. Tokens use lowercase letters/digits/underscore/hyphen. Use one stable token for the same payment account; never enter full account numbers. References retain exact case. The server hashes the namespace/reference with a versioned identity definition; category, amount, date and job are excluded from identity. Operators must supply truthful receipt identity: heuristics cannot prove that different references represent different cash transfers.

One company/source identity has one permanent lineage. Root reservations survive voids and replacements. One normalized Turo source also has one structural root reservation. Replacement rows reserve no new root. Voiding does not release a receipt for reassignment to another job.

The table has no generic `updated_at`. Business/source facts cannot be updated or deleted. Only coherent recorded-to-voided metadata is mutable. Corrections atomically void the current head and insert a same-kind/source/authority/company/job successor. A different source requires a void plus a separately evidenced new source; transferring the same source to another job is deferred.

## Reversals, claims and duplicates

A reversal is actual returned/clawed-back money, stored as a positive amount with negative economic direction and its own receipt identity/evidence. It references the original incoming receipt lineage. Replacement resolves to its current head without rewriting historical references. Payer, source category/namespace, currency and chronology must be compatible. Active reversals cannot exceed that receipt. Parent correction must preserve reversal budgets; a parent with active reversals cannot simply be voided.

Optional claim association is context only. It requires an owned retained job-condition/claim relationship and confirmation. `estimated_repair_amount`, `approved_reimbursement_amount`, `paid_amount` and status do not establish receipt. No job assignment is inferred from vehicle/date.

Database root uniqueness rejects hard duplicates. Bounded same-company candidates based on amount/date, payer, reference similarity, claim association or evidence checksum are warnings, never merges. Recording and finalization require the current candidate fingerprint, explicit distinct-payment confirmation, and a reason when warnings exist. The finalization event freezes that review; newly changed candidates suppress final economic authority until reviewed again. B3.1 does not reconcile operating expenses or maintenance.

## Turo authority: deliberately unavailable

The schema reserves typed normalized/raw transaction FKs, a recognition fingerprint and source snapshot. Linked monetary fields would remain null, with validated money/date read from the existing source rather than duplicated as B3 authority.

`VehicleDamageRepairRecoveryTuroSource` is fail-closed and has no environment/config activation switch. The stored normalized schema supplies nullable external identity/raw/trip/vehicle linkage, amount, defaulted currency and a transaction date; it does not prove settlement status, actual paid date, damage-specific receipt classification, reversal parent, or receipt-level allocation within payouts. Those missing semantic contracts cannot be inferred from descriptions or signed amounts. Local fixtures demonstrate that generic reimbursement, approval, failed payment, forecast, aggregate payout and unproven reversal labels do not establish paid-receipt semantics. External entry cannot serve as a Turo fallback.

Current writer inventory:

| Writer/path | Effect relevant to future recognition |
|---|---|
| `TuroNormalizedTransactionRepository::upsert` via `TuroEarningsImportService` | Inserts or overwrites source amount/date/classification/raw linkage/trip/vehicle identity |
| `TuroNormalizedTransactionRepository::backfillEventClasses`, invoked by migration 000009 | Mutates classification |
| `TuroTransactionRelinkingService::relinkForTuroVehicle` | Mutates normalized trip/vehicle linkage |
| `TuroRawTransactionRepository::create` via earnings import | Appends raw provenance; no runtime raw update/delete method found |
| `TuroNormalizedTripRepository::upsert` via trip imports | Can change linked trip ownership and provenance |
| `VehicleTuroListingRepository::createMapping`, `remap`, `deactivateFleetConflicts` through `TuroVehicleMappingService::map`, `TuroVehicleMatcher::match`, and `FleetVehicleService::create` | Changes listing ownership used by normalization/relinking |
| `UnknownVehicleOnboardingService::onboard`, `TuroTripReconciliationService::execute` | Invoke listing mapping, trip reimport and normalized-transaction relinking; all must participate in future per-source guarding |
| `FleetVehicleSeeder::seedTuroListing`, fleet foundation/convergence migration ownership writes | Seed/remap listing and vehicle company context; not an ordinary import, but must not bypass recognized-source ownership guards |
| `FleetVehicleService::update` | Can change company context of linked vehicles |
| `TuroEvChargingUpdateRepository::updateOptimistically` | Changes trip charging facts, not a damage receipt; must remain outside receipt semantics |
| Legacy schema FKs, destructive migration downs, administrative SQL | Potential parent deletion/cascade or source alteration outside runtime writers |

No ordinary runtime normalized/raw transaction deletion API was found. Structural B3 source FKs retain referenced rows, but they do not make source facts immutable.

No shared Turo writer guard is claimed or enabled in B3.1. Activation would require fixture-proven paid receipt/reversal semantics and a bounded per-source guard across every economically relevant writer and ownership path. Existing imports/relinks do not acquire repair aggregate locks; changing all paths safely is deferred rather than expanding this external-receipt slice into an import subsystem refactor.

The future guard must discover old/new vehicle and recognized owner-job contexts first, lock ancestors in the shared order, revalidate normalized/raw sources, and atomically mutate the source plus invalidate affected recovery completeness with one version/event/audit per material affected job. Timestamp-only changes must be nonmaterial. Unsafe source deletion/ownership relocation must fail closed. No import may synthesize a recovery correction or reversal automatically. Fingerprint-only validation cannot protect a mutable source between check and commit.

## Finalization and economics

Job fields `recovery_finalized_at`, `recovery_finalized_by`, `recovery_finalization_note` are all null or all populated; the note is required and bounded. No recovery total, balance or economic-status cache is stored.

Recovery finalization requires expected version, current ledger fingerprint, valid active lineages/evidence, current duplicate review, explicit completeness confirmation and note. Empty-ledger finalization requires explicit no-recovery confirmation. Neither a terminal job nor finalized repair cost is required.

The event freezes active IDs, entry/document fingerprints, net recovery, review, actor/date/note and confirmations. Durable content fingerprints exclude job version. An unrelated version/event alone does not invalidate completeness. New receipt, reversal, void or replacement clears the three fields in its single command/event. Standalone invalidation requires current finalization fingerprint, reason and confirmation, without monetary changes; stale evidence does not prevent that operator action.

Standalone invalidation also remains available when structurally present recovery history has corrupt semantic lineage. Its event retains the exact entries and marks totals unknown/source review required. This exception does not permit recording, finalization or correction through corrupt history, and it never repairs or rewrites monetary rows.

Recorded net recovery is active receipts minus active reversals, using decimal strings. No active receipt and unfinalized completeness means unknown/no recovery recorded. A fully reversed receipt establishes recorded zero; explicit empty finalization establishes confirmed zero.

Final host-borne repair balance is finalized valid B2.3 invoiced cost minus finalized valid net recovery. The read model checks active evidence/lineage and the immutable finalization snapshots, not merely populated markers. Stale source/evidence, inconsistent snapshots, unresolved review or unknown/unfinalized cost suppress the result. B3 commands never clear B2.3 cost finalization; existing B2.3 invalidation naturally removes economic finality.

Excess recovery is preserved as a signed negative balance and displayed as recovery exceeding recorded repair cost by the exact amount, with scope review. It is not labelled profit/income/gain or clamped to zero. Explicit scope review is necessary for final authority; cost finalized later can expose a new scope-review gate. Recovery before an invoice, recovery without repair and mitigation remain supported without inventing zero repair cost.

## Transactions, evidence and audits

The existing repair runner owns every material B3 transaction, upload lifecycle, version, event and audit. B3 semantic hashing is separate; B2.1/B2.2/B2.3 hash definitions remain unchanged. GET, replay, validation failure and no-op create no version/event/audit. Same-key replay returns the original committed receipt before stale-state validation. Post-COMMIT lost acknowledgement returns a frozen same-key retry payload, without deleting committed evidence or recreating money.

Global lock order: sorted vehicles -> sorted jobs -> conditions -> memberships -> estimates -> scope -> repair documents -> B2.3 costs -> selected claims -> normalized transactions -> raw sources -> B3 recovery entries -> files -> images -> events/audits. Disabled Turo phases acquire no source locks. B3.1 takes no expense/maintenance locks. No new ancestor is acquired after a lower phase; repeated reads of already locked rows are permitted. Different-vehicle duplicate receipt insertions serialize on the permanent source unique key, then reject/replay after rollback without acquiring the other job's ancestors.

Document kinds `recovery_payment` and `recovery_reversal` reuse private storage and same-job document authorization. No raw file/image IDs or external-link-only evidence. Referenced documents remain permanently unarchivable, including voided/replaced history. B2.2 archive checks B3 retention before metadata locks, using locking current reads even inside an older repeatable-read snapshot. Partial B3 schema fails closed.

Audit deltas: record/reversal with existing evidence = job + entry (2); with new evidence = job + document + entry (3); void = job + entry (2); replacement with existing evidence = job + predecessor + successor (3), with new evidence = 4; finalization/invalidation = job (1). Events themselves are not audited. Automatic invalidation adds no extra version/event/job audit. Replay = zero. Turo mutation audit expectations remain deferred with activation.

## Migration and release boundary

Migration `2026-10-07-000034_CreateVehicleDamageRepairRecoveries.php` is additive and refuses destructive down migration, even when empty. It does not backfill from any existing monetary/narrative source. New tables explicitly use established Damage Ledger `utf8mb4_general_ci`; no semantic reason justified introducing unicode_ci. Numeric FKs and versioned lowercase SHA identities avoid collation-dependent authority. Code/currency checks use binary comparison. MariaDB's JSON snapshot uses its binary-collated LONGTEXT alias intentionally; other text columns use the explicit table collation.

The exact local v0.30.2 App manifest contains 39 migration files; accepted Shield/Settings history adds 3 (one Shield migration and two explicitly pinned Settings migrations), yielding the accepted full ledger 42. Applying only 000034 yields 43. Tests cover the actual dependencies and prefix-safe B2.3 trigger construction before the B3 upgrade. Failed/partial DDL requires inspection and authorized recovery, never a blind rerun.

B3.1 never contributes financial activity, changes recovery allowlists, or changes company/vehicle profitability reports. No expense/maintenance link, reporting-authority switch, allocation, global dashboard or automatic backfill is included. B3.2 must explicitly reconcile domains before report integration.

Recommended release is v0.31.0 after separate final review and release preparation. Implementation leaves version/CHANGELOG unchanged and paths unstaged. Production/publication/deployment and genuine live acceptance require later explicit authorization. Genuine receipt evidence may be accepted before shop evidence; final host cost remains unknown until both independent authorities are valid and finalized.
