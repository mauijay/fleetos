# Vehicle Damage Ledger B2.1: work and physical outcomes

B2.1 records vehicle work separately from physical damage. The canonical B1 condition remains the authority for current damage. A vendor report or completed job does not remove damage from that projection. Only an explicit operator inspection confirms physical repair.

## Records and authority

`vehicle_damage_repair_jobs` records one continuing effort/order, its intent (mitigation or repair), category, vendor snapshot, lifecycle and aggregate version. `vehicle_damage_repair_job_items` associates any number of canonical conditions with that effort. Multiple jobs may address the same condition. Withdrawal preserves the membership, result and history. `vehicle_damage_repair_job_events` contains immutable snapshots, operator, occurrence/recording times, resulting version, command identity and committed receipt. The repositories expose no event update/delete or membership deletion methods.

The migration is `2026-10-06-000030_CreateVehicleDamageRepairWork`. It adds only those three tables and nullable `vehicle_damage_item_events.repair_job_event_id`. Existing condition events keep a NULL reference. It does not infer work from legacy text, maintenance or claims. SQLite uses native ADD COLUMN rather than rebuilding condition-event history. MariaDB DDL is not transactionally atomic: apply only through a separately authorized deployment procedure after verifying migration readiness. Destructive rollback is refused, even with empty tables; restore an approved backup.

If migration is interrupted, keep writes disabled and inspect the actual schema, constraints and migration history before choosing an approved recovery procedure. A blind rerun is not a recovery plan. Once B2 operational history exists, do not re-enable old v0.27.2 write code: it lacks the canonical membership guard. A future release requires protected sequencing of code, authorized migration and verified schema readiness before writes resume.

## Lifecycle

| Current state | Legal next states |
| --- | --- |
| planned | scheduled, in_progress, deferred, cancelled |
| scheduled | scheduled (reschedule), in_progress, deferred, cancelled |
| in_progress | deferred, completed, cancelled |
| deferred | planned, scheduled, in_progress, cancelled; completed only if work already started |
| completed / cancelled | planned through explicit same-order reopening |

Normal creation is planned at version 1 with one or more explicitly confirmed current canonical conditions and unassessed results. Scope is revalidated under lock, including the displayed original/canonical relationship and fingerprint. A stale alias selection is rejected rather than retargeted.

Historical mitigation is a separate recording mode. It requires mitigation intent, confirmation that work was performed, a recording reason and completion note. It creates a completed version-1 job, mitigated memberships and one historical event, without physical changes. Vendor and exact performed time may remain NULL. It creates no invented schedule/start time.

Every material aggregate command produces one version increment and one event. Scope/result changes and multi-condition completion also increment once. GETs, replay, failed validation and rejected no-ops do not increment. Details corrections require a reason; performed intent/category cannot be rewritten. A cancelled/completed job must be reopened before changing scope.

## Results and inspection

Results are unassessed, unchanged, mitigated, partially_repaired, repair_reported, repaired and failed. Mitigation jobs allow only unassessed, unchanged, mitigated and failed. Ordinary results require a finding; unassessed requires an explanation. Completion must assess exactly all active memberships, without omissions, duplicates or extras. Results and completion leave physical state unchanged. A newly asserted repaired result is rejected.

Repair confirmation requires repair intent, an active membership, actual started work, an eligible job state (in_progress, deferred or completed), current canonical condition, expected version/fingerprint, confirmation, inspection time and note. The single transaction updates the job version, membership result and condition resolution metadata, appends a correlated repaired condition event and audits the three changed objects. Completion can preserve an already confirmed repaired result.

## Reopening

Generic B1 terminal transitions remain terminal. The separate `VehicleDamageService::reopenRepairedCondition()` command permits only repaired canonical conditions. It requires a fingerprint, UUID, confirmation, observed time, note and one of repair_failure, residual_damage or incorrect_repair_confirmation. It clears current resolution metadata, restores open status and appends repair_reopened; the old repair event and audit remain intact. A genuinely new occurrence uses the B1 new-incident workflow.

When the latest repair was confirmed through B2, reopening must use that exact job and membership, including a membership subsequently withdrawn from future scope. The transaction increments the job, appends condition_reopened, correlates the physical event and sets failed, partially_repaired or unassessed respectively. Withdrawal metadata remains intact. It does not reopen the job. Legacy/manual repaired conditions can reopen without a job; their durable command receipt is stored in the existing condition audit JSON under the vehicle lock.

Job reopening means the same continuing effort/order, with explicit confirmation and continuing_order or incorrect_cancellation reason. It requires at least one current eligible member condition, resets current-cycle schedule/start/completion fields and preserves old values in event snapshots. Distinct later visits/orders normally use new jobs. It never reopens physical damage.

## Integrity, concurrency and replay

B1 historical linking locks and revalidates the source/target, then checks **any** source B2 membership before making an alias or worsening/history mutation. Active, withdrawn, cancelled and completed work all require that source condition to remain canonical. A target with work can remain canonical. Conditions without work retain B1 behavior. Readiness checks all required columns, exact index column sets and required foreign keys including update/delete policies. Missing or partial B2 schema explicitly refuses writes and the integrity guard; it is never silently bypassed.

Commands lock the owned live vehicle first, then jobs, sorted selected/canonical conditions, sorted memberships and current event/audit state. MariaDB uses FOR UPDATE current reads; SQLite uses BEGIN IMMEDIATE. B1 and B2 share the vehicle lock. Post-wait validation uses current state. The B2 fingerprint includes identity, ownership, physical details, severity/status, resolution metadata, canonical pointer, update metadata and latest condition-event ID. B1 fingerprint semantics are unchanged.

When a MariaDB caller already owns a transaction, the command uses a savepoint. Failure removes its writes without committing or discarding the caller's earlier work. A database failure still marks the outer transaction failed. SQLite commands require immediate transaction ownership and fail safely if another transaction or writer prevents it.

Job command identities are company-scoped lowercase UUIDs. SHA-256 covers a normalized semantic envelope including authorized context, actor and expected state. It excludes CSRF, generated IDs and server recording time; trims text, normalizes empty optionals, IDs/times and collection ordering. Same key/hash returns the committed receipt before stale-version checks. A changed payload rejects. Rollback leaves the key reusable. A fully rolled-back B2 transaction resets CodeIgniter's failure status so the same connection can retry; a failed outer transaction remains failed. Duplicate-key recovery re-enters the owned locking replay lookup.

Audits use the existing global audit log and record only changed jobs/members/conditions. Immutable event insertion is not separately audited. Audit failure rolls back all associated physical and operational writes.

Standalone receipts are looked up by exact command key inside authorized condition audit JSON, returning at most two rows to detect duplicate receipts. Ordinary audit data cannot supply the reserved receipt structure. Malformed unrelated JSON is excluded safely; an invalid matching receipt or duplicate receipt refuses the command. No fourth receipt table is created.

## Operator presentation and access

The vehicle Damage & Condition workspace presents active work and completed/cancelled history. Condition cards and Movement Checklists share two vehicle-scoped work queries, with compact state/count links and no checklist work forms. Job screens show scope, findings, legal lifecycle commands, vendor snapshot and immutable history. Repair reported is visibly inspection pending. Confirmation and reopening have separate review screens.

Routes remain under the existing authenticated admin vehicle group with POST CSRF and PRG. Ownership is derived from the active company and live vehicle; job/member/event IDs never grant access alone. The vendor selector includes only active vendor companies already referenced by owned maintenance or existing owned B2 work. Unknown vendors can be captured as text; B2 creates no vendor records.

## Boundaries and deferred work

B2.1 does not write claims, normalized transactions, maintenance, expenses, financial activity, evidence/files/images, incident attribution, movement, commitments or availability. It does not create financial reporting entries or count maintenance twice. B2.2/B2.3 estimates, quote acceptance, uploads, invoices, payments, actual cost, claim recovery, net host cost and reconciliation remain deferred.

## Local validation

Permanent `VehicleDamageRepair*Test` suites cover migration fresh/upgrade preservation, lifecycle/outcomes, replay, canonical integrity, ownership, presentation, boundaries and independent-connection MariaDB contention. MariaDB gates require `B21_MARIADB_CONFIG` and `B21_MARIADB_DATADIR`. The fixture verifies loopback host, nonstandard port, actual MariaDB version and server datadir inside ignored `build/`, creates a random synthetic database and drops only that verified fixture database. No production connection is allowed. Missing opt-in skips these tests and is insufficient for release approval.

Migration verification also covers configured prefixes and writes ignored local preservation hashes and FK/index inventories. SQLite is checked with db_ and x_ prefixes. MariaDB checks the default unprefixed fresh/upgrade path and an x_-prefixed baseline upgrade. The prefixed MariaDB fixture first runs the actual accepted baseline migrations, then renames its isolated tables while preserving their constraints: migration 000021 already generates a 64-character FK name, so any nonempty prefix prevents a fresh baseline migration before 000030 runs. B2.1 supports upgrading a prefixed schema but does not rewrite those accepted migrations or claim to fix fresh prefixed baseline creation.

`node tests/browser/vehicle-damage-repairs.mjs` starts a loopback-only synthetic PHP router and fresh Chrome profile; screenshots/results/databases remain under ignored build/. It checks 390, 1280 and 1920px, actual forms, lifecycle/outcomes, stale/replay feedback, keyboard traversal, labels, overflow and checklist integration. The router is test support and must never be deployed as an application entry point. Run frontend build first; optional B21_BROWSER_PHP/INI/CHROME variables select local executables.

Before any separately authorized commit/release, review the exact diff and schema, run the full suite with real MariaDB gates, static analysis, syntax/style/diff, frontend/JS and browser checks, and scan changed files for private data. This local implementation does not authorize commit, push, tagging, release metadata or deployment.
