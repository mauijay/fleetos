# Repair estimates and private documents (B2.2)

B2.2 records quoted repair amounts and source documents on a Work & Repair job. The quote is a single total for a frozen selection of job memberships; it is never allocated or multiplied across conditions. It creates no invoice, actual repair cost, vendor payment, claim, recovery, maintenance log, operating expense, or financial report entry. Those later workflows remain deferred to B2.3 and subsequent work.

## Authority and immutable revisions

`vehicle_damage_repair_estimates` stores an explicit series UUID and consecutive revision number. Revision 1 has no predecessor. A new revision must reference the latest revision of that exact company/job/series; an obsolete predecessor cannot create a branch. Different vendors normally use different explicit series. The service never infers a series from a vendor name.

Amount, currency, vendor facts, quote date, expiry facts, reference, recording mode, original note, historical recording reason, predecessor, series, and revision are frozen. A correction records a new revision. Only disposition, replacement link, and update metadata can change. Monetary input is a string, normalized to two decimal places without floating point. Zero and `9999999999.99` are valid. Floats, negatives, scientific notation, grouping separators, excess precision, and overflow are rejected. This phase supports explicitly confirmed USD.

Each revision has at least one immutable `vehicle_damage_repair_estimate_items` row referencing a job membership. Its JSON freezes membership ID, canonical condition ID, description, zone, nullable panel, damage type, severity, and physical status. No claim or financial fact is included. Later membership or physical condition changes leave that snapshot intact. A new acceptance checks that every quoted membership remains active and canonical; a historical acceptance remains recorded when later work or scope changes.

## Source completeness

`current_quote` requires a repair-intent job, vendor snapshot, authoritative date, amount, currency, coherent scope, and explicit confirmation of those source facts. Creation includes an active verified binary `estimate` document in the **same command**. An external reference alone does not satisfy this requirement. The whole quote, scope, document, job version, event, and audits commit together.

`historical_incomplete` requires explicit amount/currency confirmation and a nonempty historical recording reason. Unknown vendor, date, expiration, reference, and document remain NULL. The UI labels it **Historical estimate — source details incomplete**. This mode can never be accepted. Attaching a source later does not promote it; an authoritative source requires a new `current_quote` revision.

## Selection, disposition, and expiration

The job's nullable `accepted_estimate_id` is authoritative. Under ordered locks, either it is NULL with no accepted estimates, or it identifies the one accepted estimate in the same company/job. No amount is copied onto the job.

Acceptance requires an owned live repair-intent job in planned, scheduled, in-progress, or deferred state; the latest received authoritative revision; current active scope; an active verified source; a current job version and estimate fingerprint; and explicit confirmation. Replacing an existing selection also requires its ID, fingerprint, and an explicit reason. A replacement in the same series supersedes the prior accepted revision. A competing series withdraws the prior accepted selection. Creating a newer revision alone preserves an older acceptance. A received predecessor is superseded only with explicit confirmation when its revision is created. Rejecting is limited to received quotes; withdrawing accepts received or accepted quotes, clearing the pointer for an accepted withdrawal. Terminal dispositions never return to received.

Expiration is derived on reads and rechecked after locks on acceptance. `expires_at` expires at the exact instant, inclusive; `expires_on` remains valid through that calendar date. These fields are mutually exclusive. Both NULL means expiry unknown. No GET or clock passage writes an expired status. An accepted quote remains historically accepted after expiry, with expired validity displayed separately.

## Private documents

`vehicle_damage_repair_documents` supports exactly estimate, work order, before photo, after photo, and other. Each immutable business document has exactly one authority: shared file metadata, shared image metadata, or a nonempty external reference. Context and content corrections archive the old record and create a new document. If both estimate and membership are linked, the membership must be in that revision's frozen scope.

New uploads use `RepairDocumentStorageService`, backed by the shared `PrivateEvidenceStorageService` and `FileRepository`. Storage is `writable/uploads/repair-documents/company-{company_id}/YYYY/MM/` with random names, directory mode 0700 and file mode 0600. PDFs, JPEGs, PNGs, and WebPs up to 10,485,760 bytes are supported. Photo kinds require verified image MIME. The facade freezes server-verified SHA-256, MIME, size, and normalized original filename. External references have no binary snapshots; they are escaped text or validated HTTPS links, never server-fetched. Executable schemes, filesystem paths, and control characters are rejected.

Reuse requires an explicitly identified owned source document/job/vehicle. Raw file or image IDs are rejected. Source jobs are known before lower-order locks. Checksum deduplication stays inside a validated company directory and verifies current metadata, containment, MIME, size, and actual bytes. It may reuse storage but always creates a separate business document record. A corrupt candidate fails closed. Cross-company storage reuse is prohibited.

Downloads follow authenticated admin → exactly one active company → live owned vehicle → owned job → owned document → current metadata → contained private binary. They return a generic not-found result for unavailable or foreign content. Filename and MIME are safe and verified; disposition defaults to attachment with `nosniff` and private/no-store caching. Every path component, including the company root, rejects symlinks. Frozen checksum/MIME/size and actual bytes are checked on every download and acceptance source validation.

Archive sets timestamp, actor, and reason together. It preserves the record and binary for authorized history/download, while removing that document from new acceptance support. There is no hard-delete API.

## Transactions, replay, and history

All seven commands (`createEstimate`, `createRevision`, `acceptEstimate`, `rejectEstimate`, `withdrawEstimate`, `attachDocument`, `archiveDocument`) participate inside the accepted B2 command runner. A material command increments job version exactly once and inserts one immutable job event. Physical references are NULL and no physical condition event is produced. Changed jobs, estimates, scope rows, and documents are audited; the job event itself is not separately audited. An audit failure rolls back business rows.

Event JSON retains the accepted `job`, `members`, and `receipt` keys and adds a `b22` namespace containing quote/scope/document and disposition/replacement state. Revision events include predecessor supersession; no extra supersession command is needed. Existing B2.1 payload normalization and receipt hashes remain unchanged.

Company/command UUID remains the replay authority. B2.2 hashes semantic quote/scope/source facts, actor, expected version/fingerprints, confirmations, and reasons. Upload descriptors contain SHA-256, verified MIME, size, and normalized filename; temporary paths, generated storage IDs, server times, and CSRF are excluded. Receipts expose `source_descriptor` so a committed upload can replay after its temp file moves. That descriptor never authorizes a new attachment without valid bytes or an owned source. Same key/different semantics rejects.

Estimate fingerprints include immutable facts, disposition/lineage, frozen scope, current scoped memberships/conditions, supporting documents and current metadata, and latest related event. Document fingerprints include immutable context/content, archive state, current metadata, and latest related event. Neither relies solely on timestamp.

Locks proceed through vehicles in ascending ID order, all known source/target jobs in ascending order, target conditions, memberships, estimates, scope rows, documents, file/image metadata, then event/audit state. MariaDB uses locking current reads; SQLite uses BEGIN IMMEDIATE. Commands moving new binaries must own the outer transaction. Proven rollback removes only newly created uncommitted bytes; reuse, deduplicated storage, and archived content are retained. Ambiguous commit outcomes preserve bytes for reconciliation.

## Schema and operational gates

App migration `2026-10-06-000031_CreateVehicleDamageRepairEstimates.php` creates only the three B2.2 tables, adds nullable accepted estimate authority to existing jobs, and adds required membership context uniqueness. Existing event schema and B2.1 rows remain intact. MariaDB amounts use DECIMAL(12,2); SQLite uses canonical TEXT. Composite FKs enforce estimate/scope/document company/job contexts. MariaDB enforces the accepted pointer with a composite FK. SQLite uses native ADD COLUMN, a foreign key, and prefix-aware INSERT/UPDATE ownership triggers without rebuilding populated jobs.

B2.2 readiness verifies required columns, exact indexes, foreign keys, and SQLite ownership trigger definitions. Missing integrity infrastructure disables B2.2 commands; unrelated B2.1 reads remain available. Existing jobs have NULL selection and display **No repair estimate recorded.** No narrative, mitigation, claim, maintenance, reimbursement, or payout is treated as quote authority. The movement checklist keeps its Work & Repair indicator/link and has no quote dollars or forms.

`down()` refuses on both empty and populated schemas because quote revisions, source documents, and their authority are append-only history. It never removes binaries. MariaDB DDL is not transactional: after a partial migration, inspect actual tables, indexes, constraints, accepted-pointer column, and ledger before recovery. Do not blindly rerun or infer completion from one table's existence.

Local verification includes actual baseline/prefixed SQLite and disposable MariaDB migrations, string-money parity, estimate/document/scope/disposition/replay/rollback/authorization/firewall tests, independent MariaDB connection contention, accepted B2.1 regressions, full PHPUnit, PHPStan, syntax/style checks, frontend/JS checks, and synthetic browser flows at 390, 1280, and 1920 pixels. MariaDB fixtures require explicit loopback configuration and verify server datadir lies inside the ignored build directory before creating or dropping their own random synthetic database. Browser fixtures use their dedicated loopback router, never the application entry point.

Final review and a future v0.29.0 release require separate authorization. This implementation does not update release metadata, commit, publish, deploy, or migrate production.
