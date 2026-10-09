# Guest Commitment Projection

`GuestCommitmentProjectionService` provides a single-trip `forTrip()` and bulk
`forTrips()` read model. The Guest Commitments page, movement checklist, readiness
reader, and Movement Board use this model. Counts mean operator-visible current
rows: current purchased selections (including confirmed or informational context)
plus active manual commitments. Removed purchases and completed/canceled manuals
belong to history. Movement summaries filter configured phases; unknown operational
meaning stays visible for review. A board preview is bounded to two rows without
truncating obligations or its count.

Purchased authority remains `turo_extra_reservation_snapshots` and
`turo_extra_selections`, with explicit company/source-ID mappings in
`fleet_extra_source_mappings` and configuration in `fleet_extras`. Fulfillment is
owned by `trip_extra_fulfillments` and its audits. Manual instructions, acknowledgments,
completion and cancellation remain owned by `fleet_trip_commitments` and its audits.
The projection stores nothing and never copies a purchase into a manual commitment.
Names, quantities, prices and similar labels never merge identities.

Purchased identities are `extra_selection:<selection_id>`; manual identities are
`manual_commitment:<commitment_id>`. Both have company/trip-qualified internal
identities. Purchased rows carry canonical and source names, source reservation,
quantity and price references, snapshot/observation evidence, freshness, configuration,
phase, blocking policy, fulfillment, current operational basis, permitted actions,
and audit context. Manual rows retain instruction, handling, state, phase, acknowledgment,
completion and optional canonical Extra reference. Exact selection links are deferred.

The loader validates vehicle-derived parent company ownership before loading child
authorities. It uses eight data SELECTs for batches containing purchases, regardless
of whether there are 1, 50 or 500 trips. Schema metadata is warmed/excluded consistently
in query-count tests. A request has an explicit safety limit of 10,000 requested trips,
rows per authority, and total snapshot purchase items. Each data read fetches at most
one row beyond its limit to detect overflow. Exceeding any limit rejects the whole
projection with a descriptive error; no partial obligations or false empty result is
returned. Smaller requests retain all obligations and history. Callers should split
oversized batches or review retained history; paging larger histories is future work.
MariaDB reads use a read-only repeatable-read
transaction so concurrent imports cannot mix authorities from different database
snapshots. SQLite uses a read transaction. No GET creates fulfillment, mappings,
trip links, commitments, reconciliation writes or audits.

## Source evidence and freshness

`ExtrasVerificationFreshnessPolicy` remains unchanged: the preparation window and
maximum complete-observation age are 24 hours, and the advisory horizon is 72 hours.
A preparation-qualified observation must meet the policy threshold and have no
unresolved subsequent refresh failure or conflict. The observation timestamp controls
source age; import/creation time cannot refresh it.

The projection selects the latest trusted complete observation. Partial, failed,
conflicting and future-dated observations leave trusted purchase evidence intact
and show a refresh warning. A trusted complete empty observation clears current
purchases even if old selection rows or aggregate earnings data remain. It does
not prove a refund. Unverified evidence shows a warning, never a false empty truth.
“No active guest commitments” requires no current purchased/context rows, no active
manuals and no unresolved verification/synchronization warning.

When a persisted selection is ahead of trusted evidence (for example, an imported
future observation changes quantity or removal), confirmation/reopen actions are
withheld until the operational bases agree. Existing commands read persisted
selections; the projection must not offer actions against different displayed work.

Owned linked trip-ID aliases remain supported.
Board freshness warnings use the same projection verification as their rows, including
trusted alias evidence, rather than a separate canonical-reservation interpretation.
Unmapped selections retain source identity and quantity, with mapping/review guidance.
Mapped rows show the saved name
first and preserve a differing Turo label. Disabled definitions do not hide purchases.
Unavailable/unconfigured definitions require review rather than invented product
blockers. Quantity remains nullable and supports fractional values; absent quantity
requires verification. Monetary references never determine configuration or fulfillment.
No aggregate-specific amount warning is enabled because this milestone has no
fixture-proven allowlisted CSV amount contract.

## Fulfillment, readiness and handoff

Existing configuration fields are reused: `fulfillment_type`,
`requires_operator_confirmation`, `readiness_blocking`, `default_action_label`, and
`fulfillment_phase`. Pack, install, configure, logistics, informational and none retain
their meanings. None is unconfigured. Informational context requires confirmation
only when explicitly configured to require it.
Required informational rows also remain visible when synchronization or pickup closure
makes their confirmation action unavailable.

The read model recomputes the existing fulfillment basis without altering its hash
contract. Quantity, mapping, configuration, action, phase and vehicle changes can make
stored completion insufficient even if reconciliation was missed. Price changes do
not. Missing fulfillment for configured blocking work remains an unsatisfied readiness
requirement and displays synchronization guidance; GET never creates the row.
Historical complete snapshots also detect missed reactivation reconciliation, preventing
an old confirmation from silently satisfying a reappearing purchase.
Reactivation evidence stays within the selection's company/reservation identity.
Legacy confirmations without a frozen snapshot use confirmation-record time converted
from the application timezone to UTC; they do not treat a removal before that
confirmation as a later reactivation. This does not change observation freshness.
Delayed import reconciliation preserves a confirmation already recorded for the
current snapshot while still reopening a changed operational basis.

Only an owned, matching-vehicle, unvoided actual handoff closes pickup preparation.
Closure does not establish fulfillment. Unconfirmed or changed pickup work stays
visible for review; automatic reconciliation preserves the pre-handoff fulfillment
record. Completion and reopening cannot bypass pickup closure. Return work remains
actionable. Writer locks serialize material Extra commands with import/configuration
and parent movement writes; reads do not wait on those row locks. Same-basis repeated
completion remains idempotent.

Confirmation means the configured operator check was recorded: packed does not prove
delivery, configuration does not guarantee technical feature operation, and logistics
review does not complete a movement. UI timestamps say “Confirmation recorded.”
Future material fulfillment audit JSON includes `operational_context.version = 1`:
selection/snapshot/source identity, saved Extra identity/name, source label, quantity,
vehicle, type, phase, action and blocking policy. Old audits are never rewritten.

Related manual and purchased rows in overlapping phases show a possible overlap
warning. Multiple selections of the same product make the warning explicitly ambiguous.
Each keeps independent confirmation and history. A manual note cannot discharge a
purchased obligation.
Movement and board presentation retain the already-resolved vehicle-policy context
for manual energy overrides without another projection query or changing membership.

Removed selections remain discoverable even if they never had fulfillment. Frozen
operational audit context supplies historical configuration when available; legacy
history says configuration unknown and does not reuse current catalog semantics.
Source snapshots, prior confirmations and audits remain intact.

Configured one-way options use logistics/return behavior and the authoritative planned
return location. Prepaid recharge is informational/entitlement context when configured
that way. Feature Extras use configure confirmation. These reads never infer an airport
from a label, activate features, rewrite schedules, create stock, custody or movement
facts, change charging/battery observations, or create financial postings.

Waiver/refund lifecycle, exact manual-to-selection links, `occurred_at`, feature
compatibility/automation, and inventory remain deferred. This milestone needs no
migration or new persistent authority. Financial, claims and Damage Ledger authorities
are unchanged.

## Local verification

Permanent SQLite tests cover evidence, coexistence, operational basis, reactivation,
history, handoff, missing-row readiness, ownership, and query budgets. A 1,025-selection
trip proves obligations are not capped. Disposable MariaDB tests cover actual row-lock
contention and committed-snapshot reads. The browser harness uses synthetic data and
loopback servers only, at 390, 1280 and 1920 pixels. All MariaDB gates require an explicit
disposable configuration and verified build-directory data store; they must run rather
than be skipped for final review.
