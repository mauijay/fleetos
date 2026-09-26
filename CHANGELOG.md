# Changelog

## v0.20.0 — Turo Odometer Ingestion

Release date: 2026-09-25

### Added

- Add automatic Turo pickup and return odometer ingestion into Vehicle Health.
- Add replay-safe immutable source identities and correction/supersession handling for imported odometer readings.
- Add validation that prevents malformed, mismatched, cross-company, canceled-trip, and distance-only source data from becoming odometer observations.

### Changed

- Include imported Turo odometer observations in the existing Vehicle Health authority model alongside manual observations.
- Use scheduled trip start and end as provenance-qualified temporal anchors because Turo does not provide separate physical odometer-reading timestamps.

### Release Boundaries

- This release requires no migration or schema change.
- Historical Turo odometer backfill is not included.
- Existing legacy `fleet_vehicles.odometer_miles` remains non-authoritative and is not updated by this release.

## v0.19.1 — Future-Staging Custody Hotfix

Release date: 2026-09-25

### Fixed

- Preserve active guest custody when a future reservation has already been staged.
- Prevent cross-trip staging and pickup confirmation while another trip retains guest custody.

### Release Boundaries

- This release requires no migration, schema change, dependency change, frontend asset source change, Vite asset replacement, or production data correction.

## v0.19.0 — Vehicle Health & Reminders

Release date: 2026-09-24

### Vehicle Health Foundation

- Add immutable, timestamped, vehicle-scoped health observations.
- Preserve observation source, actor, observed time, correction lineage, and void history.
- Derive current state from authoritative observations rather than mutable vehicle fields.

### Tire Pressure

- Add four-wheel tire-pressure observations for LF, RF, LR, and RR using whole PSI values only.
- Preserve recommended PSI with each observation.
- Support vehicle policy for recommended PSI, acceptable minimum and maximum, optional safety minimum and maximum, and a recurring inspection interval.
- Treat 42 PSI recommended and 40–44 PSI acceptable as a configured operating-policy example, not a migration default.
- Generate **Correct tire pressure** when a value is outside the configured acceptable range.
- Keep maintenance attention nonblocking unless an explicit safety threshold is configured and crossed; do not infer unsafe or dangerous language from ordinary maintenance bounds.

### Tire-Pressure Reminders

- Derive recurring tire-pressure checks from the latest valid observation plus the configured interval.
- Restart the interval when a fresh observation is recorded.
- Give a known out-of-range observation precedence over a routine check.
- Emit one current tire-pressure action per vehicle instead of duplicate Check and Correct actions.
- Distinguish **Next routine check** from **Routine check was due** in the UI.

### Odometer Authority

- Add timestamped authoritative odometer observations and `CurrentVehicleOdometerResolver`.
- Keep legacy `fleet_vehicles.odometer_miles` as compatibility context only until confirmed through an authoritative observation.
- Display legacy-only mileage as **Legacy odometer — unverified** and allow **Record current odometer** from the vehicle page.
- Exclude missing odometer authority from fleet-wide Vehicle Health action counts.

### Fleet-Wide Vehicle Health

- Add the Vehicle Health & Reminders projection to the Command Center and integrate actionable health work with Today's Mission and the Operations Queue.
- Surface actionable tire issues while suppressing healthy or upcoming items, vehicles without tire policy, inactive vehicles, and odometer setup-only reminders.
- Use the actionable health issue in Fleet Activity instead of generic health noise where applicable.

### Vehicle Health UI

- Add a Vehicle Health section to the vehicle workspace showing the latest tire-pressure observation, PSI policy, authoritative or legacy odometer state, current reminders, observation history, and policy configuration.
- Add actions to record tire pressure, record odometer, configure tire-pressure policy, and correct or void health observations.

### Return and Movement Integration

- Keep tire-pressure work out of routine trip checklists.
- Surface due or abnormal tire-pressure context only in eligible preparation workflows.
- Provide optional return pressure recording rather than a mandatory per-trip check.
- Keep health reminders derived rather than persisted as checklist tasks.

### Odometer Transition

- Stop ordinary Vehicle Edit from independently establishing odometer authority.
- Use health observations as the authoritative source while retaining legacy scalar mileage as compatibility context and cache.

### Company Scope and Audit

- Scope Vehicle Health reads and mutations explicitly to the company and reject cross-company access.
- Record authenticated operator provenance for manual observations.
- Supersede observations through corrections instead of rewriting history.

### Migration

- Add `2026-09-24-000026_CreateVehicleHealthObservationFoundation`.
- Create `vehicle_health_observations`, `vehicle_tire_pressure_observations`, `vehicle_odometer_observations`, and `vehicle_health_policies`.
- Create no observations, policies, tire-pressure defaults, acceptable or safety ranges, odometer backfill, or other business rows.

### Custody Hotfix Compatibility

- Retain all v0.18.3 trip-aware custody chronology behavior.
- Prevent a prior-trip backfilled recovery from overriding a later trip's guest handoff.
- Keep the Movement Board's visible rented status as **Rented**.

### Deferred

- Turo odometer ingestion remains a planned follow-on and is not included in v0.19.0.
- This release does not add tire rotation, tread-depth tracking, tire lifecycle or tire cost per mile, cabin filters, wiper reminders, registration/safety/insurance reminders, loan-payment reminders, software-update reminders, or Tesla API/telemetry.

### Release Boundaries

- This release requires migration `000026`, includes a schema change and frontend source change, and requires production Vite asset replacement.
- This release has no dependency change, business-data migration, automatic policy creation, or automatic policy seeding.

## v0.18.3 — Cross-Trip Custody Chronology

Release date: 2026-09-23

### Custody Authority

- Add a shared, trip-aware current vehicle custody authority.
- Prevent a later reservation's authoritative guest handoff from being overridden by a backfilled event belonging to an earlier reservation.
- Keep current guest possession active until that same trip has an authoritative return or recovery transition.

### Cross-Trip Chronology

- Prevent prior-trip recovery and return facts from superseding a later trip merely because their stored occurrence timestamp is later.
- Exclude canceled, invalid, voided, and superseded lifecycle facts from current custody authority.

### Location Versus Custody

- Treat physical vehicle position separately from possession.
- Allow `vehicle_positioned` to describe position history without independently establishing operator possession, Ready state, or availability.
- Keep guest-held vehicles operationally Rented without fabricating live GPS position.

### Command Center and Movement Board

- Derive current rented state and current-trip context from the shared custody authority.
- Preserve future next-trip context alongside the active rental.
- Shorten the Movement Board's visible rented badge from **Currently Rented** to **Rented** while preserving internal state codes.

### Backfill Validation

- Reject historical return or recovery entry when an earlier trip is assigned a return or recovery time at or after a later trip's authoritative guest handoff.
- Continue supporting truthful historical times before the later handoff.

### Recovery UX

- Avoid blindly defaulting historical recovery to the current time when a later trip already establishes chronology.
- Show useful chronology context, including scheduled return, guest-reported parked time, and a later guest handoff when applicable.
- Preserve the convenient current-time default for contemporaneous recovery without a chronology conflict.

### Live Defect Scenario

- Fix the production scenario where an earlier trip's backfilled recovery incorrectly caused a vehicle already handed to the next guest to appear Ready at HNL instead of Rented.
- Preserve historical events for audit without automatically rewriting their timestamps.

### Release Boundaries

- This release requires no migration, schema change, dependency change, frontend asset source change, Vite asset replacement, or production data correction.
- Existing v0.18.2 Vite assets remain valid.

## v0.18.2 — Readiness Profile UI Polish

Release date: 2026-09-23

### Readiness Profile Layout

- Group **Key card applies** and **Charging adapter applies** into one coherent **Vehicle readiness items** section.
- Keep the controls visually associated instead of allowing them to separate across form-grid columns.

### Checkbox Usability

- Increase readiness-item checkboxes to 20 × 20px.
- Keep the entire label row clickable with a 44px minimum height for a larger mouse and touch target.

### Responsive UX

- Keep readiness controls grouped at desktop widths and stack them cleanly at 390–440px without horizontal overflow.
- Preserve the existing Save Vehicle layout.

### Accessibility

- Preserve native checkbox inputs, label association, keyboard behavior, and visible focus behavior.
- Add a semantic fieldset and **Vehicle readiness items** legend.

### Form Contract

- Preserve `operational_capabilities[] = key_card` and `operational_capabilities[] = charging_adapter` exactly.
- Make no controller, service, repository, validation, persistence, or readiness behavior changes.

### Manual Acceptance

- Desktop: PASS.
- Mobile 440px: PASS.

### Release Boundaries

- This release requires no migration, schema change, backend behavior change, or dependency change.
- This release includes a frontend source change and requires fresh production Vite asset replacement.

## v0.18.1 — Vehicle Edit Identity Fix

Release date: 2026-09-23

### Vehicle Edit Identity

- Fix a latent vehicle-edit defect where raw operational-profile metadata could overwrite the canonical fleet vehicle ID in the enriched read model.
- Preserve `fleet_vehicles.id` as the authoritative vehicle identity used by vehicle edit forms.

### Fleet Number Immutability

- Keep existing assigned fleet numbers immutable through ordinary editing while allowing unchanged values to save normally.
- Continue rejecting genuine fleet-number changes and preserve the supported assignment workflow for previously unassigned vehicles.

### Profile Enrichment

- Merge only supported operational-profile domain fields in `FleetVehicleService` instead of blindly merging raw profile-row metadata.
- Prevent profile primary keys and metadata from replacing vehicle identity or unrelated fleet-vehicle fields.

### Energy Range Editing

- Allow v0.18.0 energy range edits to save on vehicles that already have assigned fleet numbers.
- Support changing a legacy 75% target to a 70% minimum and 80% preferred maximum while dual-writing the legacy target to 70%.
- Keep energy range validation and readiness semantics unchanged.

### Security and Scope

- Preserve company scoping, fleet-number uniqueness, and immutable-number protections.

### Test Coverage

- Add regression coverage for canonical vehicle ID preservation, assigned fleet-number edits, genuine fleet-number change rejection, range edits with assigned numbers, canonical form actions, and company scoping.

### Release Boundaries

- This release requires no migration, schema change, dependency change, frontend asset change, Vite asset replacement, or business data migration.

## v0.18.0 — Energy Readiness Ranges

Release date: 2026-09-22

### Energy Range Policy

- Support minimum-only readiness, preferred readiness ranges, and unconfigured vehicle energy policy.
- Allow normal Tesla policy to be configured explicitly as 70–80% without implying that every Tesla receives that policy automatically.
- Treat the preferred upper range as informational rather than a hard ceiling. FleetOS never creates a discharge task merely because charge exceeds the preferred maximum.

### Exact Observations

- Preserve exact charge/fuel observations unchanged: a recovery recorded at 43% remains stored and displayed as 43%.
- Keep observation truth separate from policy evaluation; historical observations are never converted into ranges.

### Legacy Compatibility

- Continue supporting `ready_energy_target_percent`; a legacy 75% target resolves as minimum-only 75%.
- Do not automatically migrate or backfill 75% into a 70–80% range.
- Dual-write the new range minimum to the legacy target field for rollback compatibility.

### Trip-Specific Energy Overrides

- Add Guest Commitment support for Preferred range overrides, such as a guest-preferred 50–60% range.
- Below the lower bound, direct charging toward the range; within the range, report Ready; above the preferred maximum, remain Ready with informational context only.
- Preserve existing hard Maximum behavior, including a blocking review when exceeded.

### Shared Policy Authority

- Make `TripEnergyRuleResolver` the authoritative energy-policy resolver for operational consumers.
- Preserve exact-trip override precedence and stop operational consumers from independently reconstructing single-target policy.

### Readiness Sequencing

- Unknown energy produces exactly one measurement action.
- Known energy below the minimum produces exactly one charge/fuel action.
- Ready energy and energy above a preferred range produce zero energy blockers.
- A hard maximum violation produces exactly one review blocker.

### Same-Day Turnaround

- Allow exact prior recovery energy to drive next-trip preparation only for the exact next eligible trip on the same local calendar day.
- For example, a 43% recovery followed by a 70–80% next-trip range produces **Charge to 70–80%** without creating a synthetic pickup observation.
- Do not treat an overnight cross-midnight turnaround as same-day.

### Stale and Non-Same-Day Energy

- Keep prior recovery energy visible as Last known context without treating it as current for a later-day pickup.
- Require a fresh current Charge/Fuel measurement before evaluating later-day readiness.
- Keep cleanliness independently stateful until superseded.

### Cleanliness

- Continue Dirty/Clean state independently from energy freshness.
- Allow Dirty to remain actionable across days until a later Clean observation.

### Guest Possession and Canceled Trips

- Suppress impossible physical preparation while the guest has possession.
- Produce zero active work for canceled or invalid trips while preserving history.

### ICE and Gasoline Vehicles

- Apply the same profile model to gasoline vehicles with fuel-specific readiness wording.
- Never infer Tesla policy for ICE vehicles.

### Admin UX

- Replace the vehicle profile's single target input with **Ready energy minimum** and **Ready energy preferred maximum**.
- Use paired minimum and maximum fields for Guest Commitment Preferred ranges.
- Reject maximum-only, reversed, and out-of-range configurations.

### Migration and Release Boundaries

- Add migration `2026-09-22-000025_CreateEnergyReadinessRanges` with nullable range fields for vehicle operational profiles and trip commitments.
- Keep the migration additive, with no business-row backfill or observation rewriting.
- This release requires migration 000025 and production Vite asset replacement.
- Make no dependency, financial-behavior, or Extras commercial-behavior changes.

## v0.17.1 — Readiness Deduplication & Operator Action Labels

Release date: 2026-09-22

### Readiness Deduplication

- Pickup workflows no longer show duplicate energy actions when charge/fuel level is unknown.
- Unknown energy now produces exactly one measurement requirement.
- Once energy is known, readiness evaluates the configured or trip-specific energy rule and produces at most one energy-readiness action.
- No label-based deduplication was introduced. Exact trip and movement requirement identity remains authoritative.

### Energy Sequencing

- Unknown energy produces one **Record Charge/Fuel percentage** action.
- Known energy below target produces one **Charge/Fuel toward target** action.
- Known and ready energy produces zero energy actions.
- Trip-specific Target, Maximum, and Minimum semantics remain unchanged.
- Turnaround and recovery energy behavior remains unchanged.

### Legacy Checklists

- Preserve legacy `charge_confirmed` checklist rows in checklist history.
- Prevent legacy rows from creating a second modern readiness blocker.

### Fulfillment Action Labels

- Use **Confirm packed** for Pack fulfillment.
- Use **Confirm installed** for Install fulfillment.
- Use **Confirm configured** for Configure fulfillment.
- Use **Confirm reviewed** for Logistics fulfillment.
- Keep detailed configured operator instructions visible above the button.
- Do not derive confirmation labels through arbitrary text parsing.

### Security & Domain Boundaries

- Keep existing fulfillment POST, CSRF, permission, company ownership, actor, and audit behavior unchanged.
- Add no endpoint and no GET-side mutation.
- This release has no migration, schema, dependency, CSS, JavaScript, asset-replacement, financial-behavior, or Extras commercial-behavior changes.

## v0.17.0 — Extras Fulfillment & Trip Preparation

Release date: 2026-09-21

### Extras Fulfillment

- Allow selected Turo Extras to produce operator fulfillment requirements while keeping commercial source truth in the existing Extras tables.
- Track operational preparation for the exact trip and exact Turo Extra selection. One selection produces at most one requirement; quantity is displayed accurately but never multiplies blocker counts.

### Canonical Fulfillment Configuration

- Add admin-configured fulfillment behavior to canonical Extras: `none`, `informational`, `pack`, `install`, `configure`, and `logistics`.
- Make fulfillment phase and operator action labels configurable. Blocking behavior requires explicit operator confirmation.
- Default existing Extras safely to non-actionable. Never infer fulfillment behavior from Extra name, Turo label, or source type.

### Trip Preparation

- Surface Purchased Extras in a responsive **Trip Preparation** section on movement workflows.
- Allow physical and configuration Extras to block readiness until confirmed. Move completed fulfillment into compact history/context with completion actor and time.
- Keep informational Extras visible without creating blockers.

### Quantity-Aware Preparation

- Render quantity-aware instructions such as **Body Board ×2 — Load 2 body boards** and **Child Safety Seat ×2 — Install 2 child safety seats**, while retaining one blocker per selection.
- Preserve omitted Turo quantity as unknown rather than silently treating it as one.

### Guest Commitment Linkage

- Allow Guest Commitments to link explicitly to a canonical Extra without text matching.
- Show linked informational instructions beneath their Extra, such as a child-seat arrangement, without creating a duplicate blocker. Independently required commitments remain independent work.

### FSD, EV Recharge & One-Way Trips

- Support manual FSD preparation verification without claiming that FleetOS enables FSD automatically.
- Support informational, nonblocking Prepaid EV Recharge context without inventing a charge target or financial action.
- Support One-way Trip logistics confirmation without rewriting the official Turo schedule or vehicle position.

### Reconciliation

- Reconcile fulfillment against the exact current Extra selection with idempotent repeated imports.
- Prevent stale or partial snapshots from removing or reopening work. Complete-snapshot removal suppresses active work while preserving history; re-added selections reopen appropriately.
- Reopen completed fulfillment when quantity, canonical mapping, fulfillment policy, or vehicle basis changes. Price-only changes do not reopen operational work.

### Canceled Trips & Post-Handoff State

- Preserve fulfillment history while suppressing active work for canceled or invalid trips. Reactivation of the same normalized trip restores applicability.
- Suppress impossible physical preparation after authoritative guest handoff while retaining historical fulfillment context.

### Workflow & Command Center

- Integrate fulfillment into the existing readiness projection and Operations Queue work identity rather than introducing a duplicate Extras task source.
- Show compact Trip Preparation context on the Movement Board.

### Removed Extra History

- Keep fulfilled or pending Extras removed by a later complete snapshot visible in historical/context presentation.
- Removed Extras produce zero active actions and zero blockers and remain clearly labeled as removed with no action required.

### Financial Firewall

- Give fulfillment no price, gross, revenue, payout, recovery, expense, or financial-posting authority.
- Keep Extra quantity, selected price, gross value, realized revenue, recoveries, expenses, and financial results unchanged by fulfillment completion.
- Keep `FinancialActivityReadService`, `FinancialSummaryService`, and `VehicleFinancialSummaryService` outside the fulfillment path.

### Migration

- Add migration `2026-09-21-000024_CreateExtraFulfillment`.
- Extend `fleet_extras` with fulfillment configuration and add optional canonical Extra linkage to Guest Commitments.
- Create `trip_extra_fulfillments` and `trip_extra_fulfillment_audits` with required keys and foreign keys.
- Keep the migration additive, with no name-based backfill, financial-table changes, or production fulfillment rows.

### UX & Release Boundaries

- Preserve responsive Trip Preparation behavior at 390px, 440px, desktop, and wide desktop. Keep linked guest instructions subordinate but visible and retain dark-mode styling.
- This release requires migration 000024. It makes no dependency, financial-behavior, or Extras commercial-behavior changes.
- Include frontend CSS changes; production Vite assets must be transferred during deployment.

## v0.16.1 — Future Trip Guest Commitment Navigation

Release date: 2026-09-21

### Guest Commitment Navigation

- Make Guest Commitments directly reachable from Vehicle Trip History even when no movement checklist exists.
- Allow future booked trips to receive commitments months before operational checklists are generated; Guest Commitments remain owned by the normalized trip and do not depend on checklist creation.
- Show **Guest commitments** independently from **Open movement** in Vehicle Trip History.
- Apply the same rule to Previous, Selected, and Next Reservation Context cards: trips with an existing checklist show **Open movement** and **Guest commitments**, while trips without a checklist show **Guest commitments** only.
- Keep the selected trip's own **Open movement** link visible when its exact checklist relationship exists.

### Contextual Back Navigation

- Link the canonical Guest Commitments page back to the exact movement workflow when a checklist exists.
- Link back to Vehicle Trip History focused on the normalized trip when no checklist exists.
- Keep canceled trips historically reachable and read-only without restoring active operational work.

### Read-Only and Identity Guarantees

- Resolve navigation through exact normalized-trip and existing checklist relationships rather than vehicle, schedule, or guest-name matching.
- Keep navigation read-only: viewing these pages creates no checklist, commitment, event, assessment, positioning row, or audit record and does not change trip status or operational state.

### Release Boundaries

- Make no migration, schema, dependency, CSS, JavaScript, financial-behavior, or Extras-behavior changes.

## v0.16.0 — Guest Commitments & Trip Overrides

Release date: 2026-09-20

### Guest Commitments

- Add first-class Guest Commitments attached to the normalized Turo trip rather than vehicle or checklist state.
- Support pickup/meeting instructions, return instructions, vehicle setup, guest amenities, child-seat setup, energy overrides, timing arrangements, and other trip-specific commitments.
- Support informational, acknowledgment, task, and automatic-override handling. Required-before-dispatch tasks contribute exactly one readiness blocker.
- Record actor and time for completion, acknowledgment, and cancellation, with append-only commitment audit history.

### Trip Energy Overrides

- Add a centralized exact-trip energy-rule resolver with active trip override → vehicle profile → unconfigured precedence.
- Support Target, Minimum, and Maximum rules without modifying vehicle profile defaults or leaking overrides to surrounding trips.
- Treat Maximum 50% as Ready at 40% or 50%, and as above the guest-requested maximum requiring operator attention at 60%; never direct the operator to charge further.
- Treat Target 50% as charge-toward-target at 40%, Ready at 50%, and Ready with no further charging at 60%. Minimum retains conventional lower-bound behavior.
- Show the normal vehicle target alongside the guest-specific override when configured.

### Timing & Special Instructions

- Preserve the official Turo schedule while presenting guest-arranged times separately.
- Keep informational instructions visible without creating readiness blockers.

### Workflow & Command Center

- Surface Guest Commitments directly in movement workflows and compact special-instruction previews on the Movement Board.
- Integrate required commitment work into existing readiness and queue projections without inflating actionable counts for informational commitments.

### Canceled & Invalid Trips

- Preserve commitment records and audit history while suppressing active work for canceled or otherwise invalid trips.
- Present canceled-trip commitments and movement workflows as read-only historical state, and fail closed at routine movement mutation endpoints.
- Restore applicability from persisted state if the same normalized trip becomes operationally eligible again.

### Extras & Financial Firewall

- Keep Extras as commercial truth while allowing Guest Commitments to supplement fulfillment instructions.
- Do not change Extra quantities or pricing, create financial postings, or alter realized revenue.

### UX

- Add the canonical Guest Commitments page with responsive behavior at 390px, 440px, desktop, and wide desktop while preserving FleetOS dark-mode styling.

### Migration & Release Boundaries

- Add migration `2026-09-20-000023_CreateFleetTripCommitments`, creating only `fleet_trip_commitments` and `fleet_trip_commitment_audits`.
- Keep the schema additive with no backfill or modification of existing business rows.
- This release requires migration 000023. It makes no dependency, financial-formula, or Extras commercial-behavior changes.
- Include frontend CSS and JavaScript changes; production Vite assets must be transferred during deployment.

## v0.15.1 — Recovery Location Prefill & Progressive Disclosure

Release date: 2026-09-20

### Planned Return Prefill

- Prefill Recover Vehicle from the selected trip's structured planned return location. Home defaults to **Home**, Airport HNL defaults to **Airport HNL**, and Waikiki Hotel or Other use the existing supported location vocabulary.
- Leave unknown, missing, or unsupported planned return locations at **Choose recovery location** rather than defaulting to HNL.
- Keep the prefill operator-editable. Planned return remains a UI prefill only and never establishes physical location, records recovery, or creates an operational fact.

### Progressive Recovery Details

- Keep recovery details hidden until the operator selects a location. Home and other non-HNL recovery locations show only their relevant ordinary fields.
- Keep HNL recovery structured as Level → Row → Garage → optional detail, with exactly one location-detail field presented at a time.
- Make the mobile workflow more compact by ensuring hidden fields reserve no empty space.

### Validation & Release Boundaries

- Keep recovery confirmation and server-side validation authoritative. Changing the location selector does not create a movement event or infer HNL from an unknown location.
- Make no migration, schema, dependency, or financial behavior change in this release.
- Include frontend CSS and JavaScript changes; production Vite assets must be transferred during deployment.

## v0.15.0 — Operator Actionability & Guided Turnaround

Release date: 2026-09-19

### Operator Actionability

- Prioritize the active operational commitment more clearly. An overdue or unconfirmed pickup now appears ahead of later reservations with the correct guest and trip plus a direct **Record Guest Handoff** action.
- Keep schedule and custody truth separate: passage of the scheduled pickup time never creates guest possession, while an exact `actual_handoff` establishes it for that trip.
- Make routine operator actions prominent while keeping correction, void, provenance, and configuration controls secondary.

### Exact Movement Identity & Clear Counts

- Associate Timeline readiness by exact `trip_id + movement_type`, preventing same-vehicle movements from cross-associating readiness state or collapsing legitimate movements.
- Use scope-specific count language for pickup/return actions, vehicle-wide movement actions, and Operations Queue work.

### Guided Turnaround

- Turn Recovery Complete into actionable work: **Cleaning Required** offers **Mark Clean** and **Record Condition**, while unknown or low energy offers the appropriate **Record Fuel Level** or **Record Charge Level** action.
- Display measured low energy alongside its configured target. Present a missing target as vehicle configuration requiring **Configure Vehicle**, not a routine operator policy decision or an inferred value.
- Keep recovery exceptions independently actionable and route damage attention to the existing review workflow.

### Dynamic Operational State

- Compose recovered-state language from the work that actually remains. A later Clean observation removes cleaning language, energy wording reflects only remaining fuel/charge work, and a satisfied turnaround reports **Ready for the next trip**.
- Continue suppressing impossible physical preparation work while the guest has possession.

### Data Integrity & Security

- Reuse the existing authenticated, CSRF-protected, company- and vehicle-scoped write paths; no generic raw-event endpoint is introduced.
- Make **Mark Clean** create `vehicle_readiness_observed` with cleanliness `clean` without rewriting recovery or overwriting the recovery energy observation.
- Keep cleanliness and energy field-independent, preserve historical recovery observations, and never infer missing energy values or targets.

### UX & Release Boundaries

- Polish the Trip Facts **Record Guest Handoff** action row for a one-line desktop action and contained full-width mobile behavior. Responsive acceptance covers 390px, 440px, desktop, and wide desktop layouts.
- Include frontend CSS source changes; local Vite production assets were rebuilt for verification and remain untracked according to repository convention.
- Make no migration, schema, dependency, or financial behavior change in this release.

## v0.14.2 — Retroactive Guest Handoff Recording

Release date: 2026-09-19

### Historical Pickup Facts

- Add **Record pickup / handoff** to Trip Facts when Pickup is not recorded, including active trips without a pickup checklist.
- Allow an operator to enter the known historical actual pickup time explicitly. FleetOS preserves that `occurred_at` separately from the later recording time and creates exactly one authoritative `actual_handoff` event.
- Keep handoff location, cleanliness, charge/fuel percentage, and note optional. When readiness facts are omitted, FleetOS does not fabricate an assessment and continues to show missing facts as Unknown or Not captured.

### Custody, Conflict & Security Integrity

- Make guest custody authoritative after the recorded handoff so a stale prior-trip vehicle position no longer presents as the current operational state.
- Reject duplicate handoffs and unsafe insertion when a conflicting later return or recovery fact already exists.
- Enforce company, trip, and vehicle ownership server-side with the existing session, CSRF, and `admin.access` protections. Existing pickup-checklist handoff behavior remains unchanged.
- Record only the operator-supplied fact; FleetOS does not reconstruct missing pickup data automatically, infer location, cleanliness, or energy, create return/recovery facts, or perform a Turo API action.

### Release Boundaries

- Make no migration or schema change, frontend asset change, dependency change, or financial behavior change in this release.

## v0.14.1 — Readiness Filtering & Test Stability

Release date: 2026-09-18

### Authoritative Actionable Readiness

- Filter checklist readiness against the authoritative actionable movement set before projecting current blockers. Canceled pickup checklists no longer contribute current readiness work, while their stored rows, audits, and direct historical views remain unchanged.
- Keep valid booked pickups actionable, including applicable legacy pickup checklist codes and legitimate Photos complete work.
- Match actionable movements by exact trip identity and movement type. Identical vehicle or schedule times do not cross-match, prior-trip return or recovery facts do not suppress later pickup preparation, and same-trip handoff continues to complete pickup work.

### Reconciled Independent Work

- Keep derived cleaning, charging, energy-measurement, recovery, and recovery-exception work independent of checklist-readiness filtering.
- Keep Command Center, Movement Board, Immediate Attention, and Today/Tomorrow/Urgent readiness counts aligned with the visible actionable movement set.

### Test Stability & Release Boundaries

- Stabilize operational-facts as-of tests deterministically by evaluating corrected events after their persisted creation timestamps without weakening production as-of filtering.
- Make no migration or schema change, frontend asset change, dependency change, financial behavior change, production data repair, or checklist row deletion or auto-completion in this release.

## v0.14.0 — Vehicle Return & Recovery Workflow

Release date: 2026-09-18

### Authoritative Return Lifecycle

- Add the distinct `guest_return_staged` lifecycle state and Awaiting Recovery operational status. Guest-reported HNL parking remains explicitly unverified and never becomes the authoritative current vehicle position.
- Make Recover Vehicle the authoritative operator-possession action. `vehicle_recovered` completes the return without manufacturing a duplicate `actual_return`, while backward-compatible `actual_return` facts remain valid.
- Capture verified recovery location and measured energy—or an explicit unknown-energy reason—in one operator workflow. Known HNL rows derive their garage through the shared catalog and display in Level → Row → Garage → optional-detail order.

### Derived Turnaround Work

- Derive Cleaning Required automatically after recovery until a later authoritative Clean observation clears it.
- Derive Charge/Fuel work from measured energy against the vehicle's operational target. Unknown energy creates measurement-needed work instead of a guessed 0% value or fabricated charge action.
- Add recovery follow-up exceptions for damage, missing key, missing charge adapter, not drivable, and other note-backed issues. Resolution preserves history and clears only the resolved active follow-up; damage does not automatically create a claim.

### Simplified Return Workflow & Operational Truth

- Replace duplicate Turo-style inspection, photo, return-time, cleanliness, and energy checklist busy work with one FleetOS recovery action followed by derived turnaround work.
- Preserve legacy return checklist rows and audits as historical context without allowing incomplete deprecated rows to block current work. Pickup workflow behavior remains unchanged.
- Render event-only movement facts, including handoff, return, recovery, and staged guest return, without requiring companion assessment rows.
- Keep Command Center and Operations Queue counts reconciled to visible actionable recovery, cleaning, energy, and exception work.

### Migration & Release Boundaries

- Add migration `2026-09-17-000022_CreateVehicleRecoveryExceptions` for company-scoped vehicle recovery exceptions and their resolution history.
- Make no financial formula changes, Extras Performance implementation, or loan-payment ledger changes in this release.

## v0.13.0 — Operational Truth & Command Center Refinement

Release date: 2026-09-17

### Authoritative Operational Work

- Complete pickups only on non-voided `actual_handoff`, and returns only on `actual_return` or `vehicle_recovered`; scheduled time passage, `vehicle_positioned`, and staging do not complete a movement.
- Exclude completed movements from active Today and Tomorrow work. Keep Today, Tomorrow, and Urgent queue badges aligned with visible actionable items; loan obligations remain informational and do not inflate those counts.
- Derive cleaning actions per operator-held vehicle from an authoritative return/recovery and the latest Dirty or Clean assessment. An early actual return can create a cleaning action before scheduled return time; a later Clean assessment clears only that vehicle's current need.
- Suppress impossible charging and other physical preparation actions after guest handoff while preserving below-target readiness facts as history. After return/recovery, a later pickup can again require preparation without reactivating the completed trip.

### Checklist & Command Center Presentation

- Advance successful checklist POSTs to the next actionable requirement. Keep mobile focus below the sticky header and show actionable Return Readiness before Known/Recorded Facts and Completed Checks.
- Remove duplicate current-state cards from Daily Counts while retaining authoritative current Fleet Status in Live Operations. Label utilization as month-to-date.
- Give Fleet Snapshot, Operations Queue, and External Context consistent bordered dark-card styling; keep mobile rail labels intact while value lists wrap safely.

### Release Boundaries

- No migration or schema change, financial formula change, or Extras Performance implementation in this release.

## v0.12.1 — Extras Export Compatibility & UI Containment

Release date: 2026-09-16

### Turo Extras Export Compatibility

- Support real reservation Extra payloads using `extraType.label` and `extraType.value` for source identity, `extraPricingType` for pricing type, and `priceWithCurrency.amount` and `priceWithCurrency.currencyCode` for the selected historical price and currency.
- Normalize safe numeric source IDs to exact strings, preserve explicit quantities including values greater than one, and leave omitted quantity `NULL`-compatible.
- Read only explicit `booking.extras` or `cancelledRequest.extras` arrays: an empty array is a complete snapshot, while a missing or malformed array remains a failure rather than a zero-Extra snapshot.
- Retain the strict sanitized output whitelist; guest, private, authentication, and unrelated reservation data are not exported.

### Extras Import Workspace

- Keep unmapped Extra mapping selects, buttons, and create-new disclosures inside their cards, including narrow cards in populated four-card layouts without horizontal overflow.
- Make no schema, migration, financial-posting, or financial-reporting changes.

## v0.12.0 — Extras Source Foundation

Release date: 2026-09-13

### Canonical Extras & Source Mapping

- Add a company-owned canonical Extra catalog with explicit Turo source Extra ID mappings.
- Allow many Turo source IDs to map to one canonical FleetOS Extra while allowing the same Turo label to map to different canonical Extras.
- Keep Premium Beach Gear and Basic Beach Gear distinct, and allow Portable GPS to map explicitly to FSD Upgrade.
- Require explicit mapping and reasoned, actor-attributed remapping with an audit trail.

### Sanitized Import & Historical Activity

- Add a sanitized authenticated-browser reservation Extra exporter and strict `fleetos-turo-extras-v1` JSON import.
- Preserve reservation snapshots and historical Extra selection activity, including the originally selected price.
- Keep imports duplicate-safe and idempotent, preserve omitted quantity as `NULL`, and use complete snapshots to record removal lifecycle state.
- Prevent stale snapshots from resurrecting selections removed by newer observations.

### Operator Workflow

- Add an unmapped Turo Extra operator queue and Command Center signal.
- Add a responsive Extras Import workspace for canonical catalog, import, mapping, and remapping workflows.

### Financial Boundary

- Keep Extra commercial attribution outside existing realized revenue, recoveries, operating costs, and vehicle financial results.
- Do not add imported Extra prices to FleetOS financial statements in this release.

### Migration & Deferred Reporting

- Add migration `2026-09-13-000021_CreateFleetExtrasFoundation` for the Extras source foundation.
- Defer the final fleetwide Extras Performance report to the next slice.

## v0.11.0 — Vehicle Financial Results

Release date: 2026-09-13

### Vehicle Financial Results

- Add company-scoped per-vehicle attributable financial reporting.
- Show Attributable Realized Operating Revenue, Attributable Realized Recoveries, Vehicle-attributable Costs, and Attributable Net Realized Operating Result.
- Present attributable operating results without claiming complete accounting profitability.

### Fleet-wide / Unallocated Costs

- Keep fleet-wide expenses separate instead of artificially spreading them across vehicles.
- Keep unallocated Airport expense residuals separately visible.
- Reconcile vehicle-attributable costs plus fleet-wide/unallocated costs exactly to fleet Recorded Operating Costs.

### Source Attribution

- Use authoritative signed operating-revenue postings for Turo revenue.
- Require explicit vehicle linkage for generic operating expenses.
- Attribute completed maintenance and ended charging directly to their vehicles.
- Use only explicit Airport expense allocations for Airport vehicle costs.
- Do not infer attribution through fuzzy amount, date, or nearest-trip matching.

### Reconciliation

- Reconcile vehicle rows back to the fleet financial summary with cent-safe arithmetic.
- Keep source records authoritative without duplicating them into a synthetic financial ledger.

### Periods & Sorting

- Support Current month, Previous month, and Custom date ranges using Honolulu business-date semantics.
- Support safe allowlisted sorting and default to the highest attributable net realized result first.

### Vehicle Drill-down

- Add factual source-backed vehicle financial details for Turo revenue, generic expenses, maintenance, charging, and Airport allocations.

### Reporting Boundaries

- Keep fleet-wide/unallocated costs outside individual vehicle results.
- Exclude loan, insurance, capital/startup, ambiguous Turo fees, and forecast amounts from vehicle realized results.
- Continue deferring trip-level profitability and omit legacy ROI/profitability labels from the new report.

### Command Center

- Add a small View vehicle financial results link.
- Keep the fleet-level Financial Snapshot and v0.10.1 Command Center planning unchanged.

### Movement Checklist Polish

- Align Confirm and Record facts controls consistently.
- Preserve existing POST, CSRF, and duplicate-submit protections.

## v0.10.1 — Command Center Planning

Release date: 2026-09-13

### Fleet Timeline

- Consolidate Scheduling and Chronological views into one Fleet Timeline near the top of Command Center.
- Show all remaining Today movements plus the next three future movements by default.
- Make the full seven-day schedule available through Show next 7 days.
- Separate completed movements into a collapsed Completed today section.

### Authoritative Movement Completion

- Require authoritative actual handoff for Pickup completion.
- Require authoritative actual return or vehicle recovery for Return completion.
- Do not treat vehicle positioning alone as movement completion.
- Keep a future Return visible after its Pickup has been completed.

### Scheduling Accuracy

- Exclude stale historical reservations from current-day movements.
- Use Honolulu-local movement boundaries for Today, Tomorrow, and future grouping.
- Preserve guest first names and friendly local schedule times.

### Command Center Layout

- Center and widen the Command Center workspace.
- Improve desktop timeline density with horizontal movement rows.
- Preserve stacked mobile behavior.
- Reduce initial timeline height through progressive disclosure.

### Fleet Snapshot

- Simplify Fleet Snapshot to current positional unit groups.
- Remove redundant fleet-total and per-group counts.
- Keep Fleet Status as the quantitative count and metrics surface.

### Timeline Navigation

- Link movement rows directly to the appropriate checklist or trip workflow.
- Keep timeline clicks read-only so navigation never mutates trip state.

### CSRF Submission Protection

- Add browser-side duplicate POST protection.
- Prevent overlapping submissions from racing CSRF token regeneration.
- Preserve global CSRF, token regeneration, and the zero-exception security policy.

## v0.10.0 — Financial Truth & Planning

Release date: 2026-09-13

### Truthful Financial Summary

- Replace mixed financial metrics with company-scoped realized and recorded reporting.
- Add Realized Operating Revenue, Realized Recoveries, Recorded Operating Costs, Net Realized Operating Result, and Forecast Host Payout.
- Keep forecast payout visibly separate from realized activity.
- Remove misleading Cash Flow, Operating Profit, Lifetime Profit, and similar mixed-accounting terminology.

### Realized Revenue

- Recognize signed Turo operating-revenue transactions using transaction date.
- Apply negative signed revenue postings directly against realized revenue.
- Do not separately subtract generic Turo fee or expense rows when their semantics may already be reflected in host earnings.

### Recorded Operating Costs

- Include recorded generic operating expenses, completed maintenance costs, ended charging-session costs, and authoritative Airport operating expenses.
- Exclude scheduled or canceled maintenance, unfinished charging, and legacy Airport parking sources from the new realized-cost metric.

### Loans, Insurance & Capital

- Treat loan payments as scheduled obligations rather than realized costs.
- Keep insurance premiums as policy and obligation information until payment semantics are authoritative.
- Keep startup and acquisition records outside operating results.

### Turo Import Integrity

- Correct accounting-parentheses parsing for negative amounts.
- Continue excluding ambiguous and unsafe transaction classes.
- Do not silently rewrite existing historical normalized rows.
- Keep recovery recognition behind an exact-label validation gate.

### Company Isolation

- Explicitly scope financial queries to the active company.
- Exclude unmatched, conflicting, and ambiguous source ownership.
- Keep server-side active-company ownership authoritative.

### Decision Support

- Remove non-actionable repeat-guest recommendations.
- Preserve genuinely actionable maintenance, cancellation-exposure, and long-term-rental recommendations.
- Show a clean empty state when no recommendation requires action.

### Command Center Planning

- Show resolved payment due dates in Loan Payments Due.
- Show guest first names when available and format schedule times for Honolulu local time.
- Prevent stale historical reservations from appearing in Today, Tomorrow, or Next 7 Days.
- Separate schedule truth from operational overlap and state truth.

### Reporting Methodology

- Add explicit source taxonomy and recognition-date documentation.
- Document realized, recorded-incurred, forecast, and scheduled semantics.
- Note that historical totals may differ because ambiguous and scheduled inputs are intentionally excluded from realized operating results.

## v0.9.9 — Expense Workspace Polish

Release date: 2026-09-13

### Expense Workspace

- Balance the Expenses & Receipts summary cards on desktop.
- Give the summary cards equal sizing and centered values.
- Retain fluid wrapping and stacking on narrow screens.

### Vehicle Selection

- Simplify vehicle option labels in the operating-expense workflow.
- Show the normal FleetOS display name instead of the redundant `Fleet #N · ...` prefix.
- Keep underlying vehicle IDs, ownership validation, filters, and trip matching unchanged.

### Responsive UX

- Preserve mobile and wide-screen behavior.
- Introduce no fixed-width overflow.

## v0.9.8 — Operating Expenses & Receipts

Release date: 2026-09-13

### Operating Expenses

- Add manual operating-expense capture for ordinary fleet costs.
- Support fleet-wide, vehicle-specific, and trip-linked expenses with controlled expense categories.
- Require positive amounts with exact decimal precision.
- Support audited correction, archive, and restore workflows.

### Receipt Inbox

- Add a receipt-first capture workflow with a Needs attention queue for unclassified evidence.
- Create and link an operating expense transactionally when a receipt is classified.
- Support non-business and duplicate evidence outcomes.
- Keep missing receipts informational so they do not create queue work.

### Secure Evidence

- Add secure private storage for generic operating-expense receipts.
- Authorize previews through the company-owned receipt parent rather than a raw file ID.
- Verify MIME type and SHA-256 checksum, enforce storage-root containment, and return safe preview headers.

### Expenses & Receipts Workspace

- Add Needs attention, Recent, By vehicle, and History views.
- Add visible receipt-preview actions and authoritative evidence lists on expense details.
- Show a Recorded operating expenses total limited to active generic records; it is not a complete fleet profit-and-loss total.

### Command Center

- Add a positive-only “Expenses to classify” action when unresolved generic expense receipts exist.

### Duplicate Handling

- Distinguish duplicate receipt evidence from possible duplicate expenses.
- Avoid creating a second active inbox row for duplicate evidence.
- Warn on similar expense facts without automatically merging expense records.

### Global FleetOS UI Foundation

- Add reusable success, info, warning, and danger alerts.
- Improve validation summaries, field-level invalid states, focus visibility, and accessibility semantics.
- Add shared dark-mode form controls and readable select, option, and optgroup styling.
- Introduce no Bootstrap or third-party UI framework.

### Reporting Boundary

- Keep operating-expense capture separate from full fleet financial integration.
- Leave Airport, Maintenance, Charging, acquisition, insurance, financing, and imported Turo cost sources in their specialized domains.
- Defer full source integration and double-count prevention to Slice 3B.3B.

## v0.9.7 — Airport Receipts & Follow-up

Release date: 2026-09-12

### HNL Policy Alignment

- Stop creating new HNL Turo Access reimbursement claims under the current airport policy.
- Treat the current $14 host parking fee as a host operating cost and earnings deduction, not guest reimbursement.
- Preserve historical $21 claim values for existing legacy records.
- Use staffed-lane and attendant resolution for gate failures.

### Airport Receipts & Follow-up

- Organize existing records into Needs Setup, Ready to File, Filed / Awaiting Outcome, and History views.
- Count unresolved work once and remove resolved operating-expense, duplicate, and non-business receipts from the active queue.
- Keep historical claims fully readable.

### Claim Workflow Integrity

- Allow only `ready_to_file` to `filed` and `filed` to `reimbursed` or `denied` transitions.
- Reject invalid and terminal-state transitions without partial writes.
- Record the authenticated Shield actor for filing, outcomes, classification, and matching actions.

### Command Center

- Add one positive-only Airport Follow-up action that distinguishes ready, needs-setup, and awaiting-outcome work.
- Exclude resolved and historical work from the active summary.

### Airport Receipts UX

- Center the action-first operator workspace at an approximately 1440px maximum width.
- Add bookmarkable filters and pagination with dark-mode-readable, responsive presentation.
- Move capture forms into a secondary disclosure while preserving their existing behavior.

### Capture Workflow

- Make “Capture or log airport evidence” visually recognizable as an interactive disclosure with hover, keyboard-focus, and caret states.

### Performance

- Reduce the Command Center airport summary to one company-scoped aggregate query.
- Batch receipt evidence and remove the per-incident receipt query pattern.

### Security & Accounting

- Preserve global CSRF, Shield admin authorization, active-company scoping, and receipt-parent file authorization.
- Keep reimbursement workflow status separate from revenue and profit-and-loss accounting.

## v0.9.6 — Incidentals & Security Hardening

Release date: 2026-09-11

### Incidentals Review

- Add trip-level Incidentals Review and invoice follow-up after a configurable post-completion delay.
- Keep overdue items visible until the operator records Invoice sent or No invoice needed.
- Show Incidentals Review in Command Center only when positive actionable work exists.

### Earnings Plan & Deadline Rules

- Resolve effective-dated fleet defaults with optional vehicle overrides while preserving immutable trip snapshots.
- Leave ambiguous or unresolved historical trips as Plan needed rather than guessing from payout percentages.
- Apply plan-specific filing windows: More earnings — 72 hours; Balanced — 96 hours; More peace of mind — 120 hours.
- Keep review timing separate from the filing deadline.

### CodeIgniter 4 CSRF

- Move CSRF protection to the global `App\Config\Filters` before filter with no exceptions.
- Cover login and all browser mutations by default, including previously uncovered airport forms.
- Remove redundant route-local CSRF declarations.

### Airport Authorization

- Require Shield `admin.access` for airport operations and reimbursement routes.
- Scope airport controllers, services, repositories, and Command Center counts to the active company.
- Fail cross-company relationships closed and store explicit company ownership on airport runs and unmatched receipts.

### Secure Receipt Access

- Replace raw file-ID streaming with company-authorized receipt-parent routes.
- Harden private-path containment, MIME validation, and filename/header handling.
- Preserve receipt preview and matching iframe behavior.

### Authentication Hardening

- Replace state-changing `GET /logout` with a globally CSRF-protected POST route.
- Reuse Shield's native logout action and preserve its redirect and flash behavior.
- Leave `GET /logout` unavailable so browser GET requests cannot log users out.

### Framework Architecture

- Preserve CodeIgniter-native filters, routing, and services with Shield-native authentication and permissions.
- Introduce no parallel authentication or security layer.

### Deployment Note

- Before production migration, run migration 000019's ownership preflight.
- Stop if any legacy airport run or receipt has missing, conflicting, or ambiguous company ownership; never guess ownership because only one company is active.

## v0.9.5 — Current State & Operator Ergonomics

Release date: 2026-09-10

### Current Vehicle Position

- Record vehicle-scoped Home, HNL, or Other positions independently of trips.
- HNL storage captures structured Garage, Level, and Row details while remaining distinct from reservation staging or guest possession.
- Active guest possession blocks generic positioning.

### Current Readiness

- Record current cleanliness and charge or fuel without rewriting historical trip facts or changing physical location.
- Later current-readiness observations can drive future pickup preparation while historical pickup and return observations remain preserved.

### Readiness Logic

- Shared readiness projection prioritizes target pickup or staging facts, then later current readiness, then prior return fallback.
- Voided, superseded, and future facts remain excluded, and vehicle energy targets remain separate from recorded observations.

### Pickup Preparation

- Photos complete replaces redundant inspection and separate exterior/interior photo actions while preserving auditable legacy rows.
- Tesla workflows use Key card present; conventional keyed vehicles use Keys present; charging-adapter work appears only where applicable.
- Guest pickup and Turo Access instructions are no longer routine manual blockers, while Guest handoff remains a separate lifecycle event.

### Return Workflow

- Routine returns no longer require manual disposition; recorded cleanliness and energy drive cleaning, charging, or fueling work.
- Disposition is reserved for exceptional Maintenance, Claim or damage review, and Offline or unavailable holds.
- Historical disposition values remain readable.

### Vehicle Operations & Navigation

- Vehicle Details now combines current position and readiness in a compact Current Operations section.
- Trip History is directly accessible from Vehicles and Vehicle Details, with contextual Open Movement navigation where relevant.
- Current-location labels use canonical presentation, including Waikiki Hotel and genuine Unknown states.

### Operator Ergonomics

- Movement and Trip History pages use a centered, approximately 1440px workflow width on large and ultrawide displays.
- Mobile and standard desktop layouts remain responsive without excessively stretched forms.

### Safety & Performance

- New writes retain session, admin permission, CSRF, company scope, authenticated actor, transaction, and replay protections.
- Capability reads remain batched, readiness remains bounded at 11 queries, and no migration is required.

### Backlog

- Existing airport POST-route security hardening remains deferred.
- Slice 3B remains Reimbursement & Deadline Protection and broader expense design.

## v0.9.4 — Operational Readiness & Command Center

Release date: 2026-09-09

### Derived Readiness

- Readiness derives from authoritative operational facts and genuine human actions rather than ceremonial checklist completion counts.
- Historical checklist completion remains auditable without overriding current operational truth.
- Pickup preparation, guest handoff, return intake, and turnaround work remain distinct lifecycle concepts.
- Blocking and additional actions are reported separately.

### Movement Workflow

- The active checklist wall is replaced with compact Known, Action Required, Lifecycle, and preparation or turnaround presentation.
- Authoritative return, time, charge or fuel, cleanliness, location, and staging facts automatically satisfy corresponding readiness knowledge.
- Inspections, condition photos, damage checks, and disposition remain explicit operator actions with operator-oriented wording.
- Historical checklist evidence remains available in collapsed history.

### Vehicle Positioning

- Historical movement locations remain attached to their events while current physical position is tracked separately.
- Later positioning can move a vehicle Home without rewriting an earlier Waikiki return or other trip fact.
- HNL staging preserves structured Garage, Level, and Row details without implying guest handoff.
- Positioning plans and scheduled locations remain intent only; canonical Row terminology is retained while legacy stall storage remains hidden compatibility data.

### Command Center

- Fleet Snapshot appears at the top of the activity panel with mutually exclusive Rented, Home, HNL, Other, and Unknown buckets.
- Snapshot and Fleet Status share authoritative current-state semantics rather than future schedules.
- Later authoritative return, recovery, or positioning facts correctly end rented possession.

### Movement Board

- Shared readiness projections replace stale legacy checklist counts.
- Cards show compact blocking and additional counts plus one highest-priority next action; full detail remains on the Movement page.
- True same-day turnaround semantics remain preserved.

### Operations Queue

- Today, Tomorrow, and Urgent scopes use bookmarkable, read-only GET navigation.
- Actionable movement work links to filtered Movement Board views, while import issues, vehicle matching, airport operations, and Airport Receipts use canonical domain routes.
- Entries appear only when measurable work exists, keeping the queue focused on operator action.

### Safety & Performance

- Dashboard and movement navigation remain GET read-only, with company-scoped batched reads for readiness and Fleet Snapshot.
- No synthetic checklist completion writes were introduced, and duplicate guest handoff and exact position replay protections remain intact.
- Responsive behavior is validated across mobile and desktop.

### Backlog

- Broader Receipts & Expenses / Operating Expenses discovery is recorded for Slice 3B while Airport Receipts remains specialized.

## v0.9.3 — Operator Flow Polish

Release date: 2026-09-08

### Movement Navigation

- Movement Board current guest and trip rows link directly to the correct movement.
- Contextual Open movement actions cover rented, staged, overdue, and turnaround states.
- Normal operation no longer requires typed checklist URLs.

### Trip Facts

- Pickup and Return facts display independently and remain visible together.
- Missing facts show Not recorded, while superseded and voided facts remain excluded.
- HNL location formatting and Charge/Fuel presentation are preserved.

### Correction Safety

- Explicit Correct pickup and Correct return actions bind corrections to the intended event and assessment instead of the latest fact.
- Wrong-trip actions remain fact-specific, and validation preserves the selected correction context.

### Time Entry

- Separate date and exact-minute time controls retain Honolulu-local timestamp composition.
- Early-handoff protection remains unchanged.

### Reservation Context

- Compact Previous / Selected / Next context skips canceled neighboring reservations.
- Selected canceled reservations remain displayable, and canceled reservations remain visible in full Vehicle Trip History.

### Navigation Flow

- Command Center -> Movement -> Trip History -> adjacent Movement.
- Vehicle Details -> Trip History -> Movement.
- Mobile and keyboard accessibility are improved throughout the flow.

## v0.9.2 - Historical Trip Repair Fix

Release date: 2026-09-07

### Wrong-Trip Repair

- Completed historical reservations can be valid repair targets.
- Staging facts no longer falsely conflict with guest handoff repair; an `actual_handoff` conflicts only with another active `actual_handoff`.
- Genuine conflicting handoffs remain blocked and are shown to the operator.
- Canceled, deleted, invalid, different-vehicle, and implausibly distant trips remain excluded.
- Append/void/supersede audit history remains preserved.

## v0.9.1 - Movement Accuracy & Recovery

Release date: 2026-09-07

### Airport Pickup Accuracy

- Keep HNL staging distinct from guest handoff, so vehicles can be staged early without becoming Currently Rented.
- Use Confirm Guest Pickup to record the actual handoff separately while preserving structured HNL garage, level, and row staging.

### Handoff Safety

- Movement Board handoff actions now open an entry form instead of writing immediately, with an editable actual handoff time.
- Warn when a handoff is more than two hours early and require explicit confirmation before recording it.

### Wrong-Trip Recovery

- Repair movements recorded on the wrong trip by selecting a same-vehicle candidate and reviewing a FROM -> TO preview.
- Preserve append/void/supersede audit history and recompute current and next reservation state after repair.

### Trip Context & Navigation

- Show Previous / Selected / Next reservation context and vehicle trip history with clickable trip and movement navigation.
- Link Vehicle Details -> Trip History and show guest names on Movement Board cards.

### Turnaround Accuracy

- Require two adjacent reservations for a same-day turnaround and enforce same-vehicle, same-day return-to-next-pickup rules.
- Do not treat a short trip that starts and ends on the same day as a turnaround by itself.

### Minor Fix

- Correct the site credit email address.

## v0.9.0 - Movement Intelligence & Positioning

Release date: 2026-09-04

### Movement Operational Facts

- Record explicit guest handoffs and actual returns, distinguish actual movement from scheduled expectations, and track current and handoff locations.
- Capture Clean/Dirty and Charge/Fuel observations against vehicle energy profiles and operational capabilities, with append-only corrections and audit history.

### Movement Board Intelligence

- Show authoritative states including Currently Rented, turnaround attention, and overdue confirmations, with meaningful operational blockers instead of ceremonial checklist totals.
- Surface the next confirmed trip beyond today's calendar, planning horizons, Turo import freshness, and stale-data warnings.

### Location Intelligence

- Add exact operator-managed aliases using the Home, Airport HNL, Waikiki Hotel, and Other Delivery taxonomy while preserving original imported location text.

### HNL Airport Operations

- Record structured HNL garage, level, and row facts with row-first deterministic garage identification and no parking-stall concept.
- Treat International Garage / Blue as the approved Turo garage and call attention to Terminal 1 / Green or Terminal 2 / Red positioning.

### Vehicle Positioning

- Provide deterministic FleetOS recommendations for HNL-to-HNL airport stays and Waikiki transportation dependencies, expressed as Recommended, Consider, or Flexible.
- Keep operator positioning plans separate from FleetOS recommendations, with audited basis snapshots and invalidation when underlying facts change.

### Architecture & Reliability

- Keep Command Center GET requests read-only and create checklists and workflows only through intentional write paths.
- Centralize projection eligibility within a 30-day operational horizon, prevent completed or canceled history from generating new work, and preserve legacy checklist history.
- Add migration `000015` for Movement Operational Facts and migration `000016` for Movement Planning Controls.

## v0.8.1 - Vehicle Registration & Desktop Productivity

### Desktop Productivity

- Use wider vehicle-page desktop space beginning at 1440px while keeping the Fleet Registry and Edit Vehicle major sections single-column through 1699px.
- At 1700px and wider, show two Fleet Registry cards per row and arrange Edit Vehicle into two major columns.
- Preserve existing mobile and tablet behavior and scope all new width and layout treatment to vehicle pages.

### Ownership & Registration

- Add registered owner, registration renewal due, and Hawaiʻi safety inspection due fields.
- Support company, individual, or joint-owner descriptions as free text, independent of financing and company ownership.

### Edit Vehicle Information Architecture

- Add Ownership & Registration and Service & Lifecycle sections.
- Clarify VIN placement within the vehicle specification and identity structure.

### Registration & Compliance Workspace

- Add a Registration & Compliance summary and display missing values as Not entered.

### Registration Data Integrity

- Keep all registration and compliance fields nullable with no inference, automatic date calculation, or backfill, so existing vehicles remain valid.

### Deferred Capabilities

- This release does not add compliance alerts, a Command Center due-soon or overdue panel, scheduled reminders, borrower/co-borrower/obligor tracking, or automatic registration or safety-date calculation.

## v0.8.0 - Vehicle Capital Management

### Vehicle Workspace

- Add a vehicle detail workspace with Overview, Acquisition, Financing, Financial Performance, and Notes & Documents sections.

### Acquisition

- Add an optional acquisition record with Purchase Order Subtotal, acquisition and funding methods, source and reference, rebates and incentives, trade-in credit, and cash paid at closing as distinct recorded facts.
- Keep the existing vehicle `purchase_date` as the acquisition date without reconstructing dealer accounting or inferring purchase-order arithmetic.
- Link an acquisition explicitly to its original financing agreement when applicable.

### Financing

- Add reusable lenders and support multiple financing agreements per vehicle.
- Record original principal, APR, term, payment, first-payment and maturity dates, status, and refinance lineage with paid-off and refinanced states.

### Balance and Payoff

- Add dated authoritative principal and payoff snapshots with as-of dates, source provenance, and snapshot history.
- Allow same-date corrections to update the authoritative snapshot while preserving prior values in actor-aware audit history.

### Data Integrity

- Use `loans.original_principal` as the sole source of original financed principal and preserve the explicitly linked acquisition financing agreement through later refinancing.
- Avoid arbitrary-loan selection when multiple agreements exist.
- Preserve legacy `current_balance` compatibility while treating it as non-authoritative when no dated snapshot exists.

### Security and Audit

- Require `admin.access`, CSRF protection on financial writes, authenticated actor IDs in audit history, and transactions around financial changes.

### Terminology

- Replace misleading Fleet Value, Fleet Equity, accounting Profit, and ROI labels with conservative factual financial terminology.

### Gradual Backfill

- Keep existing vehicles valid without acquisition or financing records and create no synthetic financial data during migration.

### Not Included

- This release does not add market valuation, equity, depreciation, tax basis, accounting ledger or journal entries, or a fleet balance sheet.

## v0.7.1 - Vehicle Catalog and Fleet Navigation

### Catalog

- Add Truck as a normalized vehicle body style and Tan to the vehicle color catalog.
- Add production-safe catalog migration 000012 with idempotent, non-destructive convergence.

### Navigation and Selection

- Refine new interior color selections to Black, White, and Tan while safely preserving existing non-preferred interior colors during editing.
- Keep the broader exterior color catalog unchanged.
- Rename the operational Fleet navigation destination to Fleet Activity and reorder the primary workflow as Fleet Command Center, Fleet Activity, Vehicles, Turo Import, Import Issues, and Vehicle Matching.

## v0.7.0 - Fleet Vehicle Management

### Added

- Add Fleet Vehicle Management with fleet numbers, vehicle editing, and unknown Turo vehicle onboarding.
- Reconcile trips and relink normalized earnings after onboarding a vehicle.
- Add Four-Wheel Drive as a distinct drivetrain while preserving existing AWD relationships.

### Changed

- Harden earnings imports against duplicate rows and make exact-file re-imports idempotent.
- Order Daily Operations movements chronologically with Turo-consistent Starting and Ending wording and semantic badges.
- Correct company master data to 808biz, Inc. and improve responsive vehicle form actions.
- Strengthen authorization and CSRF handling for administrative vehicle and reconciliation workflows.

## v0.6.2 - Shield Protection

### Added

- Protect FleetOS application routes behind CodeIgniter Shield session authentication.

### Changed

- Disable public self-registration and magic-link login for the production app.

## v0.6.1 - Orbit Deployment CSS Fix

### Fixed

- Resolve Vite CSS assets from the generated manifest so deployed pages include the production stylesheet.

## v0.4.0 – Fleet Command Center

### Added

- Mission Control homepage
- Responsive navigation
- Fleet status cards
- Operational task panels

### Changed

- Improved service integration

### Fixed

- Navigation consistency
- Responsive layout issues
