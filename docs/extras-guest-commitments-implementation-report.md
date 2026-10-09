# Extras ↔ Guest Commitments local implementation report

This report records the implementation-stage results before the separately authorized
final review and local commit. At that stage the implementation was local, unstaged
and uncommitted. Validation evidence is retained in the ignored
`build/extras-projection-local/` directory.

1. **Branch/base.** `feature/extras-guest-commitments` was created from exact
   `f551851192b4126abc5dab8781c1d6dfd1d82de9`; HEAD remains that commit. The initial
   worktree/index were clean. Application version remains `0.32.0`; CHANGELOG is
   unchanged. No production access, commit, push, tag or deployment occurred.
2. **Root cause.** The dedicated page and board previously read active manual
   commitments while purchased Extras had a separate selection/fulfillment read
   path. The permanent synthetic reproduction proves the manual authority is empty
   while Extras contains a purchase, then verifies the shared count is one and the
   empty state is forbidden. Page rendering and board tests cover the resulting UI.
3. **Architecture.** `GuestCommitmentProjectionRepository` bulk-loads owned parents
   before child authorities. `GuestCommitmentProjectionService::forTrip()` and
   `forTrips()` share a pure composer and one supplied clock. MariaDB uses a read-only
   repeatable-read transaction; SQLite uses a read transaction. There is no projection
   persistence or duplicate purchased commitment table.
4. **Purchased model.** Each `extra_selection:<id>` has a company/trip-qualified
   identity, canonical/source identity and labels, nullable quantity, price references,
   snapshot/time/completeness/freshness, mapping/configuration, type/phase/blocking,
   fulfillment/current basis/actions, source history and operational audit context.
5. **Manual model.** Each `manual_commitment:<id>` retains its instruction, category,
   phase, handling, state, acknowledgment/completion and optional catalog relationship.
   Existing workspace audit/history presentation remains. Manual writer semantics and
   command authorities are preserved.
6. **Unified list.** The page combines current purchased/context rows and active
   manuals, visibly labeled Purchased Extra and Manual. Confirmed purchased selections
   stay current context; completed/canceled manuals and removed purchases are history.
   Completion of either source does not complete the other.
7. **Empty state.** “No active guest commitments” requires no current rows and no
   unresolved verification or missing-selection synchronization warning. A trusted
   complete empty observation can qualify; unknown/failed source verification cannot.
8. **Metadata reuse.** All behavior comes from existing `fulfillment_type`,
   `requires_operator_confirmation`, `readiness_blocking`, `default_action_label`
   and `fulfillment_phase`. No parallel configuration fields were introduced.
9. **Fulfillment.** Existing `TripExtraFulfillmentService` remains the purchased
   authority. Pack/install/configure/logistics confirmations record operator checks.
   Informational context requires confirmation only when configured to require it;
   none remains unconfigured. Confirmation does not prove delivery, feature operation
   or movement completion.
10. **Basis.** GET recomputes the existing hash contract from current operational
    configuration, quantity, mapping and vehicle. A stale stored basis cannot silently
    satisfy current work. Snapshot history detects missed removal/reappearance
    reconciliation. Actions are withheld when persisted selection work differs from
    displayed trusted evidence, including future import changes. Explicit commands
    perform writes; GET never repairs fulfillment.
11. **Freshness.** `ExtrasVerificationFreshnessPolicy` is unchanged: 24-hour preparation
    window, 24-hour maximum complete-observation age, 72-hour advisory horizon.
    Observation time controls age. Qualified evidence shows “Confirmed purchased
    Extra — as of”; otherwise the purchase is last-known with refresh guidance.
12. **Observations.** Trusted complete empty clears current purchases. Partial,
    future-dated, failed and conflicting observations preserve last trusted complete
    evidence and show unresolved warnings. Older issues resolved by a newer complete
    observation do not remain current warnings. Owned linked trip-ID aliases work.
13. **Aggregate evidence.** No aggregate-specific amount warning was activated.
    This milestone has no fixture-proven allowlisted CSV amount contract. Normal
    verification warnings apply; dollars never create item identity or fulfillment.
14. **Unmapped.** Current unmapped selections remain visible with source label,
    quantity/provenance and a mapping-review link. No product identity, fulfillment
    type or readiness policy is inferred.
15. **Mapped display.** Saved canonical name appears first; a differing Turo label
    remains visible. Only explicit company/source-ID mapping determines identity.
16. **Quantity/price.** Raw source quantity is retained, including null and fractional
    values. Missing quantity says “Quantity not supplied — verify selection.” Equivalent
    database numeric representation preserves the existing basis hash contract. Price
    remains source metadata, independent of operational identity and confirmation.
17. **Overlap.** Matching catalog references in overlapping phases show a warning on
    both manual and purchased rows. Multiple matching purchases explicitly make exact
    selection identity ambiguous. No automatic linking, completion or merging occurs.
18. **Removal/history.** Removed purchases leave current counts, including selections
    without fulfillment. Snapshots/audits/confirmation survive. New audit context freezes
    historical configuration; legacy history states configuration unknown instead of
    borrowing current catalog semantics. Reappearance needs current confirmation.
19. **Handoff/reopen.** Only owned, matching-trip/vehicle, unvoided actual handoff closes
    pickup preparation. Unconfirmed/changed work remains review context. Pickup reopening
    is guarded, automatic reconciliation preserves the pre-handoff record, and return
    work remains actionable. Handoff never implies fulfillment.
20. **Missing fulfillment.** Configured blocking work without a fulfillment row becomes
    an unsatisfied `extra_selection_sync_<id>` readiness requirement and visible
    synchronization warning. It has no invented action ID; GET does not create it.
21. **Readiness.** Existing blocking policy and phase applicability remain authoritative.
    Unmapped/unconfigured purchases show review context without invented product
    blockers. Freshness remains a separate requirement; existing work identities and
    warning deduplication are retained.
22. **One-way.** Configured logistics/return behavior uses the authoritative planned
    return location and links to an existing return checklist when available. No label
    infers HNL, and no location, schedule, staging, custody or movement facts are created.
23. **Prepaid.** Configured informational prepaid recharge remains entitlement/policy
    context. No charging, return-battery, invoicing or financial facts change.
24. **Feature.** Configure-type feature purchases show activation verification work.
    No automatic activation or new compatibility schema was added; behavior never comes
    from a product label.
25. **Board.** Intelligence bulk-loads shared projections for relevant current/next
    trips. Counts mean phase-relevant operator-visible current rows, including purchases
    and manuals. Two-row previews do not limit the count or obligation data.
26. **Checklist.** Target and future preparation lists consume the shared purchased and
    manual projection. Rows filter by movement phase. Purchased Extras render once;
    overlap, missing-row warnings and handoff/reopen state remain visible.
27. **Command Center.** Existing board summaries carry projection counts/previews;
    the dashboard's fallback uses the configured shared board service. No dashboard
    subsystem or duplicate freshness/readiness alerts were added.
28. **Queries.** Measured data SELECT counts are **8 / 8 / 8** for **1 / 50 / 500** trips
    on both SQLite and disposable MariaDB. Fixed schema metadata is warmed/excluded
    consistently. A 1,025-selection synthetic trip proves no silent obligation cap.
    Memory usage grows with requested trips and retained snapshot/audit history.
29. **Security/purity.** Parent vehicle-derived company ownership is checked before
    child loading and commands. Session/admin/CSRF/POST/PRG protections remain.
    Purchased PRG uses an allowlisted destination. Ownership and database-query tests
    verify zero projection writes and no unowned child reads; navigation GETs remain
    read-only. MariaDB reads see committed snapshots during concurrent writes.
30. **Audit context.** Future material fulfillment audits receive
    `operational_context.version = 1` with selection/snapshot/source, saved name/ID,
    source label, quantity, vehicle, type, phase, action and blocking policy. Existing
    audits are never rewritten; JSON is supplementary historical context.
31. **Deferrals.** No `occurred_at` schema or waiver/refund lifecycle. Existing
    `completed_at` is labeled “Confirmation recorded.” Manual notes cannot discharge
    purchased obligations; source removal does not prove refund. Exact selection
    linking, compatibility/activation automation and inventory remain deferred.
32. **Disabled catalog.** Disabled definitions do not hide purchases. Missing or
    unconfigured definitions keep source identity and show review guidance. Legacy
    historical configuration is not fabricated.
33. **Firewall.** Earnings, monetary amounts/reports, movement/custody/position,
    claims and Damage Ledger authorities are unchanged. No financial command hash
    changes; the fulfillment basis hash algorithm is unchanged.
34. **Migrations.** Migration files are unchanged; latest App migration remains
    `2026-10-07-000035`, with no 000036. Real existing migrations build both synthetic
    SQLite and MariaDB fixtures. The synthetic migration proof finds **41 App migrations
    applied / 0 pending**. The accepted production ledger assumption remains 44;
    production was not queried to reconfirm it.
35. **Projection tests.** Permanent tests cover empty, mapped/multiple/unmapped,
    disabled/unconfigured, all six behavior types, complete/stale/partial/failed/conflict/
    future/empty evidence, alias ownership, null/fractional quantity, price independence,
    removed/reappearing work, high cardinality, query budgets and GET purity.
36. **Manual/fulfillment tests.** Purchased-only/manual-only/mixed and ambiguous overlap
    are covered, with independent manual acknowledgment/completion/cancellation and
    purchased confirmation. Pending/completed/missing/stale basis, configuration,
    quantity/mapping/vehicle changes, idempotency and prospective audit context are covered.
37. **Handoff/readiness tests.** Tests cover actual/other-trip/voided handoff, pickup
    deficit preservation, post-handoff change, return action, blocked reopening,
    reconciliation preservation, missing-row blocker and existing freshness/readiness
    identity behavior.
38. **Board/checklist/history tests.** Actual shared projection count/preview tests,
    dedicated page render cases and checklist render assertions prove purchased/manual
    visibility and phase filtering without duplication. Removed history with/without
    fulfillment and legacy/frozen configuration behavior are retained.
39. **MariaDB concurrency.** **8 tests / 63 assertions passed**, including in the final
    full run. These required real-MariaDB tests cover six independent
    process writer races: import, mapping and configuration versus completion, handoff
    versus completion/reopen, and missing-row reconciliation. Separate connections
    verify nonblocking committed-snapshot reads and actual bulk SELECT budgets.
40. **Browser.** **48 projection checks** passed: 16 checks at each of 390/1280/1920,
    covering all requested row/evidence/configuration cases, long labels, keyboard/focus,
    no overflow, CSRF-protected confirmation/PRG and independent manual completion.
    Existing HNL browser suite also passed **9 checks** across those widths, including
    validation focus, replay, CSRF and electric/gasoline behavior. Screenshots/logs remain
    in the ignored local build directories.
41. **Existing regressions.** The focused final run passed **211 tests / 1,391 assertions**
    with three existing PHPUnit notices. It includes Extras/import/fulfillment/freshness,
    manual commitments, preparation, readiness, board, dashboard and read-only navigation.
    Existing B2/B3, financial, HNL and private-file regressions passed in the full run.
42. **Full PHPUnit.** **1,521 tests / 21,324 assertions passed**, with **0 errors,
    0 failures and 0 skipped tests**; elapsed time 20:22.545. All required MariaDB
    gates ran. Three PHPUnit deprecations and 79 notices match the prior baseline.
    The first run reached 1,508 tests with one existing B3 child-process termination;
    that exact case passed in isolation and in the final complete rerun.
43. **Static/build/JS/privacy.** PHPStan passes with no errors. Syntax passes for all 32 changed/
    new PHP files; style passes with zero fixes among 26 configured files; views also
    pass syntax. `git diff --check` passes. Frontend build passes; JS tests pass **36/36**.
    Synthetic fixture/privacy scan found no sensitive additions. Standard PHPStan exposed
    existing deployment-helper discovery errors; the local config adds those two helper
    files to `scanFiles`, without suppressions or baseline changes. `PHPRC` selects the
    existing validation ini so subprocesses avoid the host's missing imap extension.
44. **Exact changed paths.** The inventory below includes this report. Ignored build
    logs/configurations/screenshots and disposable database files are validation artifacts.
45. **Scope deviations.** No schema/release/production deviation. Narrow writer fixes
    implement the required race, stale-basis, reopening and audit guarantees. Existing
    fixtures/assertions were updated for owned-event columns, phase filtering and shared
    service injection. Only local tool configuration adjustments were needed as noted above.
46. **Blockers.** None. All required validation completed. The disposable local
    MariaDB instance was shut down after the tests; its synthetic artifacts remain
    ignored for review.
47. **Final-review gates.** Review the uncommitted diff and authority boundaries, the
    8-SELECT budgets, source truth/freshness, reappearance and handoff safety, independent
    manual completion, synthetic browser screenshots, and final validation logs. Confirm
    release metadata/migrations/index remain unchanged before any later release action.
48. **Recommended release path.** After final review, handle v0.33.0 in a separately
    authorized release task: approve the implementation, run normal release/deployment
    tooling checks against its eventual commit, update release metadata, and follow the
    project's established release process. No migration is expected; this task performs
    none of those release actions.

Exact implementation path inventory:

```text
app/Config/Services.php
app/Controllers/TripCommitments.php
app/Controllers/TripExtraFulfillments.php
app/Controllers/TripMovementChecklists.php
app/Repositories/FleetExtraRepository.php
app/Repositories/GuestCommitmentProjectionRepository.php
app/Repositories/TripExtraFulfillmentRepository.php
app/Services/Fleet/DailyOperationsDashboardService.php
app/Services/Fleet/FleetExtraService.php
app/Services/Fleet/GuestCommitmentProjectionService.php
app/Services/Fleet/MovementBoardIntelligenceService.php
app/Services/Fleet/MovementReadinessProjectionService.php
app/Services/Fleet/MovementReadinessReadService.php
app/Services/Fleet/TripCommitmentService.php
app/Services/Fleet/TripExtraFulfillmentService.php
app/Services/Fleet/TripPreparationViewModelService.php
app/Views/fleet_command_center/components/movement_card.php
app/Views/trip_commitments/components/purchased_extra.php
app/Views/trip_commitments/index.php
app/Views/trip_movement_checklists/_guest_commitments.php
app/Views/trip_movement_checklists/_trip_preparation.php
app/Views/trip_movement_checklists/show.php
docs/extras-guest-commitments-implementation-report.md
docs/guest-commitment-projection.md
tests/_support/GuestCommitmentProjectionBrowserRouter.php
tests/_support/GuestCommitmentProjectionFixture.php
tests/_support/GuestCommitmentProjectionMariaDbWorker.php
tests/browser/guest-commitment-projection.mjs
tests/database/GuestCommitmentProjectionMariaDbTest.php
tests/database/GuestCommitmentProjectionTest.php
tests/database/MovementHandoffNavigationReadOnlyTest.php
tests/database/TripExtraFulfillmentTest.php
tests/unit/CrossTripPreparationTest.php
tests/unit/MovementBoardIntelligenceServiceTest.php
tests/unit/TripCommitmentsArchitectureTest.php
```

Validation evidence: `build/extras-projection-local/full-final.log` and
`full-final.xml`, `focused-final.log`, `phpstan-final.log`, `style-final.log`,
`browser-final.log`, `browser-hnl.log`, `build.log`, `js.log`, `migrations.json`,
`scope-privacy.json` and `changed-paths.txt`. PHPStan uses the local
`phpstan.neon` discovery override described above; no baseline was changed.

EXTRAS GUEST COMMITMENTS IMPLEMENTED — READY FOR FINAL REVIEW
