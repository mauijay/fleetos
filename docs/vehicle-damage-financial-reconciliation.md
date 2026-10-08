# Repair cost and operating expense reconciliation (B3.2a)

This slice identifies two retained operational records as the same complete real-world cost. The finalized B2.3 invoice family remains repair-cost authority. The existing operating expense remains financial-report authority. Reconciliation creates no financial activity: a $6,200 expense contributes $6,200 both before and after reconciliation. Payments and refunds are settlement facts and never participate.

## Accepted authorities and reporting inventory

`VehicleDamageRepairCostRepository::head()` resolves immutable invoice replacements and genuine credit correction families. Cost finalization belongs to the repair job; `VehicleDamageRepairCostService` validates performed terminal work and private invoice evidence. B3.1 recoveries retain separate source identities and completeness. Turo recognition remains unconditionally disabled.

`OperatingExpenseRepository::recordedFinancialActivity()` selects recorded, nonarchived, positive company-owned expenses with consistent vehicle ownership, on the expense date. Receipt amounts are observations, never a second expense. `FinancialActivityReadService` reads normalized Turo, forecasts, expenses, maintenance, charging and airport sources. `FinancialSummaryService` aggregates those activities; `VehicleFinancialSummaryService` distributes the same activities plus unallocated costs. None reads B2.3 as money. These services and both report UIs are unchanged by B3.2a.

Legacy `RevenueService`, `FleetIntelligenceRepository::operatingCosts()`, the company-less `FleetStatisticsService` path and capital revenue aggregates also remain unchanged. This slice neither unifies legacy reporting nor introduces repair cost into it.

## Exact complete pairs only

One invoice root has at most one active reconciliation; one expense has at most one. A current replacement head and every associated credit lineage determine the complete positive USD invoice-family net. The expense must already exist, belong to the same company, be explicitly assigned to the job vehicle, be recorded/nonarchived/positive and have no trip link. Fleet-wide sources, mixed purposes, multiple jobs, partial amounts, multiple expenses, zero-net invoices and currency conversion are unsupported.

Operating expenses do **not** store a currency column. The snapshot explicitly records USD as the existing implicit expense-reporting contract; it does not claim source currency verification.

Amount equality uses canonical decimal strings with no tolerance. An operator confirms complete fact identity, no other job portion, no additional expense authority and no partial/mixed-purpose allocation. Date differences require an explanation; the expense keeps its original report date. Differing vendor/reference descriptors require explicit evidence review and explanation. Candidate amount/date/vendor/reference hints are suggestions, never authority.

## Immutable schema and lifecycle

App migration `2026-10-07-000035_CreateVehicleDamageFinancialReconciliations.php` creates zero rows and three necessary parent context unique indexes. It also widens the MariaDB repair-event code to 64 characters for the specified 42-character invalidation event; SQLite already permits these codes without enforcing declared VARCHAR width. Vehicle/company, job/company/vehicle, cost/company/job and expense/company/vehicle references use exact existing unsigned types and restrictive typed FKs. A compound predecessor FK retains the same company, vehicle, job and root; a unique predecessor prevents branching. There are no maintenance/recovery columns or generic source identifiers.

The table retains IDs/context, two fingerprints and bounded JSON provenance, exact amount/USD/date snapshots, reason/date explanation, actor/creation clock, invalidation actor/clock/reason, predecessor and nullable current reservation slots. No `updated_at` exists. Only `active → invalidated` is permitted; immutable triggers reject editing facts, reactivation and deletion. Invalidation clears reservations but retains all source identities. Replacement inserts a successor in the same transaction after invalidating its predecessor. Referenced expenses cannot be deleted or reassigned even after invalidation. Archive invalidates; restore never resurrects a decision.

MariaDB uses InnoDB, utf8mb4/utf8mb4_general_ci and binary JSON snapshots. SQLite/MariaDB checks enforce USD positive canonical money, coherent lifecycle, SHA-256 fingerprints, bounded valid JSON/reasons and date explanation. Readiness verifies exact fields/types/nullability, parent keys, typed restrictive FKs, indexes, checks, triggers and collation. Absent schema disables the feature. Any partial footprint blocks participating source/repair commands; unrelated reads remain available. Migration is forward-only. An older application without this guard cannot safely resume source writes after a code rollback.

## Fingerprints and read safety

The `b32a:1` fingerprint contains economically meaningful current family and retained lineage facts: root/head, invoice/credit identities, amount/USD/dates, vendor/reference, status, owned context and evidence checksums/descriptors/validity. It excludes job version, unrelated events, recording clocks and incidental timestamps. It does not reuse B2.3's job-version-dependent invoiced fingerprint.

Expense identity includes amount/date/company/vehicle/trip, category, vendor/payment method/reference, business purpose, source/status/reportability, archival disposition and receipt identity/classification/date/observed amount/vendor/duplicate/archive disposition plus file checksum/type/size/deletion/validity. Recording clocks and receipt notes are excluded. Provenance excludes storage paths, secrets and guest data; each JSON snapshot is at most 64 KiB.

Every active read verifies both fingerprints. Unexpected source/binary changes yield **stale / review required** without releasing reservations or writing history. Writer guards are the primary defense; read verification is a safety net. Finalization is required on initial create/replacement, but later unrelated job version changes do not stale a reconciliation. Explicit invalidation remains available for stale active links.

## Commands, events, audits and replay

`VehicleDamageRepairService` exposes `reconcileRepairCostToExpense`, `invalidateFinancialReconciliation` and `replaceFinancialReconciliation` using a distinct B3.2 semantic namespace. Existing B2/B3 hashes remain unchanged. Each material command owns one outer transaction, increments job version once and records one event and a receipt. Create audits reconciliation create + job update (2); invalidate audits reconciliation update + job update (2); replace audits predecessor update + successor create + job update (3). Immutable event insertion is not separately audited.

Replay returns the original committed receipt before stale version/source checks. Actor/context/action/expected version, source identities/fingerprints, exact USD amount and all decisions/reasons are hashed. Changed payload under the same UUID fails. Post-COMMIT uncertainty retains the exact original payload/key for retry, including the authenticated operator's server-side HTTP retry state. GET previews are pure; POST commands use session/admin/CSRF and PRG.

Relevant B2.3 invoice/credit mutations invalidate changed family links inside the existing command event and version. Reconciliation transition audits are additional, not another top-level event. Unrelated families and vendor payments/refunds preserve links. Referenced repair documents already have permanent B2.3 archive protection.

## Expense writer inventory and guarded protocol

Runtime expense/evidence writers are `OperatingExpenseService` manual create/correct, archive/restore, upload/attach/classify receipt, and receipt archive/duplicate/nonbusiness decisions, all using `OperatingExpenseRepository`. `FileRepository` creates metadata and reads existing files; it has no runtime metadata update/delete API. Private evidence cleanup removes only a new rolled-back binary. No expense physical-delete runtime API exists; retained context FKs also block raw SQL deletion. Migrations/seeders and administrative SQL are not runtime writer APIs; unexpected bypass changes are detected as stale.

`OperatingExpenseReconciliationGuard` owns the outer source transaction. Service callbacks declare expense/receipt, company, actor and new vehicle context before source/metadata locks. Direct repository updates and linked receipt inserts use the same protocol; entering after an unguarded outer transaction fails closed. Existing expense audits are retained. Source mutation plus reconciliation invalidation plus one affected job event/version and two transition audits commit atomically. Correct/archival callbacks re-read locked source state rather than auditing an old preview. Linked receipt reassignment fails closed.

Lock order: sorted vehicles → sorted jobs → conditions → memberships → estimates → scope → repair documents → B2.3 costs → participating claims/Turo/raw/B3.1 recoveries → operating expenses → participating maintenance/future financial sources → expense receipts → reconciliations → sorted combined files → images → events/audits. This slice skips phases without participating sources. Pre-discovery includes old/new vehicle and active job contexts. After vehicle waits, rediscover jobs before lower locks; unexpected ownership/receipt/ancestor changes abort. MariaDB uses one-shot READ COMMITTED and current locking reads, not old repeatable-read previews. Expense-only changes preserve cost and recovery finalizations.

## UI, bounds and deferred slices

Job detail shows family net, eligible expense candidates, match/date/descriptor hints, active/stale status and retained history. A GET preview freezes the selected owned pair before explicit create/replacement. Invalidation retains provenance. Missing expenses remain unreconciled; no source creation exists. Company/report badges are deferred to keep reporting unchanged.

Candidates are limited to 20 current company/vehicle expenses; cost/history/evidence workspace bounds are 1,000. Source and metadata reads are bulk loaded, independent of candidate/family count. Binary checks are bounded filesystem checks, not per-row database queries. Snapshots enforce a further size bound.

Maintenance reconciliation, recovery financial publication, Turo activation, allocations, automatic expense creation, financial amount/date rewrites, generic ledger and historical backfill remain deferred. Final review should require zero-skip MariaDB contention, full regressions, source-writer coverage, browser/security/privacy checks and unchanged company/vehicle totals. A later separately authorized v0.32.0 preparation may add release metadata and an annotated local tag; production would require separate protected publication/deployment and exact migration authorization. No live reconciliation is part of implementation acceptance.
