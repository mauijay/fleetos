# Phase 4: Fleet Command Center

Phase 4 adds the FleetOS operational homepage: a dark, responsive Mission Control surface that answers what needs attention today. Command Center v2 makes the home page the daily operating screen for the owner-operated 9-car fleet.

## Architecture

- `Home::index()` remains thin. It requests a prepared view model from `FleetCommandCenterViewModelService` and renders the page.
- `FleetCommandService` remains the operational source of truth for the Command Center snapshot: fleet status, vehicle status, today's timeline, urgent work, and future signal placeholders.
- `FleetCommandCenterViewModelService` composes that snapshot with supplemental Phase 3 service outputs into display-ready arrays for the UI.
- `DailyOperationsDashboardService` composes the v2 daily operations model from `TaskService`, `VehicleAvailabilityService`, `FleetHealthService`, `FleetStatisticsService`, and the Turo import/mapping/reconciliation attention services.
- `VehicleDailyStateService` owns vehicle daily state, same-day turnaround calculation, timeline event shaping, immediate-attention rules, and daily fleet counts.
- `MorningBriefingService` builds the deterministic one- or two-sentence briefing from the prepared movement board and attention list.
- `TripMovementChecklistService` idempotently creates and summarizes current-day pickup and return checklists for Command Center.
- Business calculations remain in Phase 3 services. Financial metrics come from `RevenueService` and `FleetStatisticsService`; operational tasks come from `TaskService`, `FleetHealthService`, `VehicleAvailabilityService`, and `FleetCommandService`.
- `AssetManifestService` resolves built Vite asset paths before rendering, so views do not parse manifests or perform file-system asset lookup.
- Views render prepared arrays and reusable components. They do not query the database and do not calculate fleet metrics.

## Route

- `GET /` renders the Fleet Command Center.

## View Model Sections

- `fleet_status` powers the fleet status metric cards.
- `mission` powers Today's Mission task cards.
- `vehicles` powers Fleet Activity cards for every Spaceship.
- `timeline` powers Today, Tomorrow, and Next 7 Days timeline cards.
- `financial` powers the Financial Snapshot cards.
- `health_alerts` powers warnings-only Fleet Health.
- `executive_kpis` powers owner-focused performance metrics.
- `activity` powers the right-side operational activity panel.
- `future_integrations` reserves space for Tesla API, weather, traffic, Google Maps, flight tracking, push notifications, SMS, email, and calendar sync.
- `daily_operations.briefing` powers the Morning Briefing.
- `daily_operations.movement_board` powers the 9-vehicle daily movement board.
- `daily_operations.timeline` powers the chronological pickup/return/delivery timeline.
- `daily_operations.attention` powers immediate operational issues only.
- `daily_operations.fleet_status` powers compact daily counts.
- `daily_operations.operational_queue` powers direct workflow actions.
- `daily_operations.financial` powers secondary financial signals.
- `daily_operations.data_honesty` lists important inputs FleetOS does not yet capture reliably.
- Movement board and timeline entries link to trip movement checklists when current-day movement records exist.

## Command Center v2 Rules

Primary vehicle state precedence:

1. `late_return`
2. `same_day_turnaround`
3. `returning_today`
4. `departing_today`
5. `currently_rented`
6. `maintenance_required`
7. `offline`
8. `available`

Same-day turnaround calculation:

- Find the first return and first later pickup for the same vehicle on the operational day.
- Calculate minutes between return `ends_at` and pickup `starts_at`.
- Critical: less than 2 hours.
- Tight: 2 to 4 hours.
- Comfortable: more than 4 hours.

Morning Briefing priority:

1. Critical attention items such as late returns or critical turnarounds.
2. Same-day turnaround summary.
3. Pickup and return counts.
4. Calm positive message when there are no tight turnarounds or urgent issues.

Reliable current data includes normalized Turo pickup/return times, guests, statuses, vehicle links, current month revenue/utilization metrics, airport delivery records, fleet health records, and import cleanup attention counts.

Not yet reliably captured: battery telemetry, cleaning completion workflow state, non-airport pickup/return locations, travel-time/departure deadlines, and guest messages.

Checklist readiness is separate from trip status. FleetOS does not mark a vehicle ready merely because a trip exists or a return time has passed. Critical checklist items must be manually confirmed, and return workflows require a disposition before completion.

Airport operations add attention for HNL movements that need staging, instructions, pickup confirmation, return recovery, parking cost review, Turo Access override receipts, claim-ready reimbursement items, filed claims awaiting reimbursement, airport receipt classification, and chase-vehicle expenses missing a run. Airport receipt inbox attention stays below urgent vehicle movement work. Expected reimbursement is shown as an operational estimate, not received revenue, and airport operations expenses are shown as operating costs rather than Turo receivables.

## Reusable Components

Components live under `app/Views/fleet_command_center/components`:

- `navigation.php`
- `metric_card.php`
- `status_badge.php`
- `task_card.php`
- `vehicle_card.php`
- `timeline_card.php`
- `alert_card.php`
- `financial_card.php`
- `activity_panel.php`

These components are intentionally generic enough to be reused by future Fleet, Revenue, Claims, Maintenance, and Reports pages.

## Design System

The UI is dark-mode first and styled in `resources/css/app.css`.

- Desktop uses left navigation, top status bar, main content, and right activity panel.
- Tablet hides the right activity panel and keeps operational content primary.
- Mobile uses a sticky disclosure-style navigation drawer, then priority sections, task cards, activity cards, and timeline.
- Cards use 8px radii, restrained borders, dense spacing, and high-contrast text.
- Color is reserved for operational priority: success, info, warning, and danger.

## Accessibility

- A skip link targets the main content area.
- Primary and mobile navigation have ARIA labels.
- The current page uses `aria-current="page"`.
- The main content area is focusable for keyboard navigation.
- Color is paired with text labels and counts.
- Focus states are visible on links, summary controls, and focusable regions.

## Future Integrations

Future-only integrations are placeholders, not fake data. Battery, location, weather, traffic, and external signals display as reserved/future states until real integrations exist.

## Performance Notes

- The controller performs no SQL and no metric calculations.
- The UI consumes a single prepared view model.
- Built asset paths are resolved by `AssetManifestService`, outside the view layer.
- The Phase 3 services continue to own optimized reads and calculations.
- Command Center v2 reuses existing service-level reads instead of adding SQL to the controller or view.
- Daily vehicle state is computed from already-prepared vehicle status, task, and health arrays to avoid view-layer calculation.
- Current-day checklist summaries are loaded as aggregates; full checklist items load only on the checklist detail page.
- Operational statuses are not cached because pickup, return, overdue, and turnaround states are time-sensitive.
- Future caching should wrap `FleetCommandCenterViewModelService::forToday()` or the underlying Phase 3 services, not the view files.

## Architectural Audit

- No business calculations were moved into controllers.
- No SQL was added to controllers or views.
- No query builder calls were added to Fleet Command Center view files.
- No manifest parsing or asset file reads are performed by Fleet Command Center views.
- Financial, utilization, ADR, RevPAD, ROI, cash flow, and forecast values are consumed from Fleet Intelligence services.
- Future metrics return `null`, empty arrays, `Future`, or `Reserved` display states rather than fabricated data.
- Reusable components are isolated as partials.
- Navigation, layout, status cards, mission tasks, vehicle activity, timeline, financial snapshot, health warnings, executive KPIs, and future integration placeholders are implemented.
- Phase 1 and Phase 2 files were not redesigned.
