# Phase 3: Fleet Intelligence Layer

Phase 3 adds reusable business services for FleetOS reporting, operations, availability, revenue, analytics, daily work, and command-center snapshots. No UI was added in this phase.

## Architecture

Fleet Intelligence keeps calculations in service classes:

- Controllers orchestrate requests and should call services directly.
- Repositories retrieve optimized data and should not calculate business metrics.
- Services answer business questions and own reusable calculations.
- Views should only render service output.
- Source-specific data remains tagged by source so future reservation sources can be added without changing dashboard contracts.

The new services live in `App\Services\Fleet`. Shared read access lives in `App\Repositories\FleetIntelligenceRepository`; it is a reporting read model, not an owner of business formulas.

## Dependencies

- `FleetIntelligenceRepository` depends on CodeIgniter's database connection and centralizes Phase 3 read SQL.
- `RevenueService` depends on `FleetIntelligenceRepository` and owns all financial formulas.
- `FleetStatisticsService` depends on `FleetIntelligenceRepository` and `RevenueService`.
- `FleetHealthService` depends on `FleetIntelligenceRepository`.
- `VehicleAvailabilityService` depends on `FleetIntelligenceRepository`.
- `TripAnalyticsService` depends on `FleetIntelligenceRepository`.
- `TaskService` depends on `FleetIntelligenceRepository` and `FleetHealthService`.
- `FleetCommandService` composes `FleetStatisticsService`, `FleetHealthService`, `VehicleAvailabilityService`, and `TaskService`.

All dependencies can be constructor-injected for tests and are also registered in `Config\Services`.

## Repository Read Contracts

`FleetIntelligenceRepository` exposes read-oriented methods that return source rows, aggregates, or operational records. It should not decide business meaning beyond query shape and lightweight source tagging.

- `fleetVehicles()` returns active fleet vehicle identity, status, trim, and model rows.
- `activeReservationCounts()` returns current overlapping reservation counts grouped into reserved and in-progress buckets.
- `reservationsBetween()` returns source-tagged reservation rows for a date range.
- `revenueMonthly()` returns month allocation aggregates from the import reporting tables.
- `revenueByVehicle()` returns allocation aggregates grouped by vehicle.
- `operatingCosts()` returns known cost buckets for a period.
- `fleetCapital()` returns fleet-wide startup cost, loan balance, and equity inputs.
- `fleetCapitalByVehicle()` returns startup cost and loan balance inputs by vehicle for ROI calculations.
- `lifetimeRevenueByVehicle()` returns lifetime vehicle revenue inputs.
- `tripAnalytics()` returns trip aggregate inputs by vehicle.
- `repeatedGuests()` returns repeat guest rows.
- `openClaims()` returns open claim rows.
- `maintenanceDue()` returns scheduled maintenance rows due by date.
- `expiringRegistrations()` returns registration rows expiring by date.
- `expiringInsurance()` returns insurance rows expiring by date.
- `activeLoans()` returns active loan rows.
- `vehiclesMissingPhotos()` returns vehicles without linked photo rows.
- `vehiclesMissingDocuments()` returns vehicles without linked document rows.
- `vehiclesMissingTuroListings()` returns vehicles without active Turo listing rows.
- `airportDeliveriesBetween()` returns airport delivery rows for a period.

## Public Method Inventory

### FleetStatisticsService

- `summary()` returns executive fleet size, availability, status, revenue, value, equity, lifetime revenue, lifetime profit, and ROI metrics.
- `currentMonth()` returns current-month revenue, utilization, ADR, RevPAD, and revenue-per-vehicle metrics.
- `vehiclePerformance()` returns per-vehicle year-to-date revenue and utilization metrics.
- `fleetValue()` returns tracked fleet value, loan balance, and equity.
- `premiumVsBase()` returns premium fleet metrics compared to base fleet metrics.
- `lifetimeRevenue()` returns tracked lifetime revenue.
- `lifetimeProfit()` returns tracked lifetime profit after startup capital.
- `vehicleRoi()` returns per-vehicle ROI metrics; ROI is `null` when vehicle capital is not known.

### FleetHealthService

- `summary()` returns all operational health alert categories.
- `vehiclesNeedingCleaning()` returns vehicles with same-day completed returns requiring cleaning workflow review.
- `vehiclesDueForMaintenance()` returns scheduled maintenance due within a configurable horizon.
- `registrationExpiring()` returns registrations expiring within a configurable horizon.
- `insuranceExpiring()` returns insurance policies expiring within a configurable horizon.
- `loanPaymentDue()` returns active loans with monthly payment data.
- `claimsRequiringFollowUp()` returns open or unpaid claims.
- `vehiclesBelowBatteryThreshold()` returns telemetry battery alerts when battery data exists.
- `missingPhotos()` returns vehicles missing required photos.
- `missingDocuments()` returns vehicles missing required documents.
- `missingTuroListingData()` returns vehicles missing active Turo listing data.
- `incompleteVehicleSetup()` returns vehicles missing one or more setup requirements.

### VehicleAvailabilityService

- `availableNow()` returns vehicles available at the requested instant.
- `availableTomorrow()` returns vehicles available tomorrow.
- `timeline()` returns reservation and airport-delivery timeline entries for a period.
- `vehicleStatus()` returns operational status for every vehicle, including next reservation, odometer, cleaning status, delivery flag, and future reservations.

### RevenueService

- `currentMonth()` returns current-month revenue metrics.
- `previousMonth()` returns previous-month revenue metrics.
- `yearToDate()` returns year-to-date revenue metrics.
- `period()` returns normalized financial metrics for an inclusive month range.
- `forecastRevenue()` returns forecast revenue for a month range.
- `completedRevenue()` returns non-forecast completed revenue for a month range.
- `cancelledRevenue()` returns cancelled revenue exposure for a date range.
- `byVehicle()` returns revenue grouped by vehicle.
- `byFleet()` returns revenue grouped by fleet.
- `byVehicleType()` returns revenue grouped by vehicle type.
- `byPremiumBase()` returns revenue grouped by premium/base segment.
- `trends()` returns monthly revenue trends.
- `cashFlow()` returns cash-flow metrics.
- `operatingProfit()` returns operating profit.
- `startupCostAmortization()` returns estimated startup-cost amortization.

### TripAnalyticsService

- `summary()` returns trip analytics for a date range.
- `tripCount()` returns trip count.
- `tripDays()` returns trip days.
- `utilization()` returns billable-day utilization.
- `averageTripLength()` returns average trip length.
- `longestTrip()` returns longest trip length.
- `shortestTrip()` returns shortest trip length.
- `repeatGuests()` returns repeat guests.
- `averageReview()` returns average review when review data exists.
- `cancellationRate()` returns cancellation rate.
- `lateReturns()` returns late return events when return telemetry exists.
- `airportDeliveries()` returns airport delivery count.
- `homeDeliveries()` returns home delivery count.
- `chargingEvents()` returns charging event count.
- `batteryViolations()` returns battery violations when telemetry exists.

### TaskService

- `today()` answers what should be done today.
- `tomorrow()` answers what should be done tomorrow.
- `overdue()` returns due task categories with work outstanding.
- `highPriority()` returns urgent claims, maintenance, registration, insurance, and battery work.

### FleetCommandService

- `snapshot()` returns the mission-control operational snapshot.
- `fleetStatus()` returns fleet status counts.
- `todaysTimeline()` returns today's reservation and delivery timeline.
- `urgentItems()` returns urgent operational items.

## Caching Strategy

Caching should be added at service boundaries, not inside controllers or views.

- Cache `FleetStatisticsService::summary()` for 5 to 15 minutes once dashboards exist.
- Cache `RevenueService::period()`, `byVehicle()`, `byVehicleType()`, `byPremiumBase()`, and `trends()` by month range because they scan reporting allocations.
- Cache `TripAnalyticsService::summary()` by date range for historical periods, but keep current-day analytics short-lived.
- Cache `VehicleAvailabilityService::vehicleStatus()` briefly, because future telemetry will make it time-sensitive.
- Do not cache `TaskService::today()` and `FleetCommandService::snapshot()` longer than a few minutes once notifications and live operations depend on them.
- Invalidate revenue and analytics cache keys after reservation import, transaction import, allocation rebuilds, maintenance changes, charging imports, and claim updates.

## Future API Endpoints

These services can support future controllers with minimal logic:

- `GET /api/fleet/statistics/summary`
- `GET /api/fleet/statistics/current-month`
- `GET /api/fleet/statistics/vehicle-performance`
- `GET /api/fleet/health`
- `GET /api/fleet/availability`
- `GET /api/fleet/availability/timeline`
- `GET /api/revenue/current-month`
- `GET /api/revenue/ytd`
- `GET /api/revenue/trends`
- `GET /api/revenue/vehicles`
- `GET /api/trips/analytics`
- `GET /api/tasks/today`
- `GET /api/tasks/high-priority`
- `GET /api/fleet-command/snapshot`

## Future Reservation Sources

Current reads are backed by normalized Turo tables because Phase 2 imports Turo trips. The service contracts do not require Turo-only callers. Repository rows include source/source-reservation fields where applicable so future sources can be added behind the repository using union queries or source-specific read models:

- Direct bookings
- Website reservations
- Dealership loaners
- Subscription fleet
- Corporate fleet
- Vehicle sharing

Financial formulas should remain in `RevenueService` as these sources are added.

## Architectural Audit

- No dashboard or UI code was introduced.
- Controllers remain orchestration-only; existing controllers contain no new business calculations.
- Views remain presentation-only; no business calculations were added to views.
- `FleetIntelligenceRepository` contains read SQL only and remains bounded to Phase 3 reporting/operations read models; business formulas live in services.
- Financial calculations are centralized in `RevenueService` and reused by `FleetStatisticsService`.
- Utilization, ADR, RevPAD, and ROI helper formulas are centralized in `FleetStatisticsService`.
- Shared SQL for Phase 3 is centralized in `FleetIntelligenceRepository`; service classes do not duplicate query-builder SQL.
- The import engine was not redesigned and existing Turo normalization/allocation logic remains untouched.
- Future telemetry-only metrics return nullable values or empty collections rather than fabricated values.
- Services accept constructor dependencies and are registered in `Config\Services`, preserving testability and dependency inversion.
- No unnecessary coupling to controllers, views, or commands was introduced.

## Known Data Gaps

The database does not yet persist live battery percentage, live location, review ratings, late-return events, traffic, weather, or explicit battery violation rules. Phase 3 exposes stable output fields for those concepts without inventing data.
