# Changelog

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
