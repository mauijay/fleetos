# Phase 5: Decision Support Engine

Phase 5 turns FleetOS from a reporting surface into a deterministic business advisor. It does not add artificial intelligence. Every recommendation is produced by explicit business rules, references measurable data, and can explain why it exists.

> Slice 3B.3B-1 temporarily suppresses pricing, fleet-optimization, revenue-forecast, and business-profit recommendation categories in the Command Center. Their legacy financial inputs are not compatible with the new company-scoped methodology. Maintenance and guest-risk recommendations remain active. Financial/per-vehicle recommendations resume only after the 3B.3B-2 read model is approved.

## Architecture

- Decision logic lives in `app/Services/Fleet/DecisionSupport`.
- Recommendation output uses `App\DTOs\Fleet\Recommendation`.
- Thresholds live in `Config\DecisionSupport`; services do not hardcode operational thresholds.
- Phase 5 services compose Phase 3 Fleet Intelligence services. They do not issue SQL queries and do not duplicate repository reads.
- `FleetCommandCenterViewModelService` consumes `DecisionSupportDashboardService` through `Config\Services` and passes prepared recommendation arrays to the Fleet Command Center.
- The Fleet Command Center renders a visible recommendations section without controller-side calculations or view-side business logic.

## Recommendation Contract

Every recommendation includes:

- `title`
- `category`
- `priority`
- `confidence`
- `reason`
- `metrics`
- `action`
- `generated_at`
- `expires_at`
- `source_service`

Priorities are `Critical`, `High`, `Medium`, `Low`, and `Informational`. Current Phase 5 services use the priority set needed by implemented rules and leave the full enum available for future rules.

## Services

### PricingRecommendationService

Responsibilities:

- Price increase recommendations.
- Price decrease recommendations.
- Premium vs base comparison.
- Occupancy analysis.
- ADR competitiveness.
- Revenue optimization.
- Confidence based on measured billable days.

Rules:

- Increase price when vehicle utilization exceeds fleet average by `pricingHighOccupancyDelta` and ADR remains within `adrCompetitivenessDelta` of fleet ADR.
- Decrease price when utilization trails fleet average by `pricingLowOccupancyDelta` and ADR is above fleet ADR by `adrCompetitivenessDelta`.
- Surface premium pricing context when premium revenue share exceeds premium inventory share by the configured utilization delta.

### FleetOptimizationService

Responsibilities:

- Vehicle utilization ranking.
- Idle vehicle detection.
- Revenue concentration detection.
- Over-performing vehicle detection.
- Premium/base capacity review.
- Expansion and replacement review signals.

Rules:

- Idle vehicle recommendations require low utilization and zero completed revenue.
- Over-performing vehicles require high utilization and completed revenue above fleet average.
- Revenue concentration is flagged when one vehicle exceeds `revenueConcentrationShare` of completed fleet revenue.
- Premium expansion review is flagged when premium utilization exceeds `segmentCapacityUtilization`.

### MaintenancePredictionService

Responsibilities:

- Upcoming service interval reminders.
- Registration expiration.
- Insurance expiration.
- Claim follow-up reminders.
- Cleaning workflow reminders.
- Future Tesla API integration points.

Rules:

- Maintenance, registration, insurance, claims, and cleaning recommendations are created only from known `FleetHealthService` outputs.
- Mileage-based tire or service prediction is not fabricated. It should be added only when reliable mileage/service interval data exists.

### GuestRiskService

Responsibilities:

- Cancellation risk review.
- Repeat guest positive signals.
- Long-term rental exposure review.
- Future battery abuse, late return, smoking violation, damage claim, chargeback, rating, and guest note rules.

Rules:

- Cancellation exposure is flagged when measured cancellation rate exceeds `guestCancellationRate`.
- Long-term rental exposure is flagged when average trip length exceeds `longTermRentalDays`.
- Repeat guests are informational positive signals based on measured repeat booking counts.
- Unsupported guest risk categories produce no recommendation until data exists.

### RevenueForecastService (legacy/deferred Command Center consumer)

Responsibilities:

- 30-day forecast.
- 60-day forecast.
- 90-day forecast.
- Monthly trend.
- Cash-flow projection.
- Forecast confidence.
- Forecast assumptions.

Rules:

- Forecasts use trailing completed revenue over `forecastLookbackMonths`.
- Cash flow subtracts measured recurring operating costs from the same lookback period.
- No seasonality is applied until enough seasonal history exists.
- Zero completed revenue returns no forecast recommendation.

### BusinessInsightService (legacy/deferred Command Center consumer)

Responsibilities:

- Human-readable executive insights.
- Premium inventory insight.
- Lifetime profit insight.
- Zero current-month revenue insight.

Rules:

- Premium insight requires premium revenue share to exceed premium inventory share.
- Lifetime profit insight is generated when tracked lifetime profit is negative.
- Zero revenue insight requires active fleet inventory and zero current-month completed revenue.

### DecisionSupportDashboardService

Responsibilities:

- Exposes serialized recommendation DTOs for Fleet Command Center integration.
- Groups recommendations into `todays_recommendations`, `pricing`, `maintenance`, `fleet_health`, `revenue`, `guest_risk`, and `business_insights`.
- Sorts today's recommendations by priority and confidence.
- Runs each recommendation service once per dashboard request and derives top recommendations from the collected category results.

## Fleet Command Center Integration

Phase 5 is visible in the Fleet Command Center through the `decision_support` view model key.

- `Config\Services::fleetCommandCenterViewModelService()` injects `DecisionSupportDashboardService`.
- `FleetCommandCenterViewModelService::forToday()` calls the dashboard service once and includes its serialized output in the page model.
- `app/Views/fleet_command_center/components/recommendations_panel.php` renders the top recommendations.
- Fleet-specific UI language was replaced with generic fleet language where Phase 5 surfaced the audit concern.

## Persistence Strategy

Recommendation persistence is intentionally deferred for Phase 5 approval.

Current behavior:

- Recommendations are generated deterministically at request time from the current repository-backed fleet metrics.
- Recommendation DTOs include `generated_at` and `expires_at`, but they are not stored in a recommendation history table yet.
- The recommendation contract is stable enough to persist later without changing the services that produce recommendations.

Rationale:

- Phase 5 is the first decision-support slice and does not yet have user workflows for accepting, dismissing, assigning, or resolving recommendations.
- Persisting recommendations before those lifecycle states exist would create audit rows without a clear operational action model.
- Existing audit logging remains available for imports and record changes; recommendation outcome audit should be added when recommendation actions are introduced.

Future migration boundary:

- Add a `recommendation_events` or `fleet_recommendations` table when recommendation action workflows are approved.
- Store company/fleet scope, recommendation identity, serialized metrics, lifecycle status, generated/expired timestamps, actor, and action history.
- Keep generated recommendations traceable to `source_service` and avoid storing any synthetic metrics.

## Confidence Scoring

Confidence is deterministic and based on data coverage, not AI probability.

- Pricing confidence increases with measured billable days in the configured pricing lookback window.
- Guest risk confidence increases with measured trip count and caps below certainty.
- Revenue forecast confidence increases with configured lookback months and requires completed revenue.
- Maintenance and compliance reminders have high confidence because they are based on known records and dates.

## Known Assumptions

- FleetOS currently supports one fleet, but Phase 5 services do not bake in company, fleet, or source IDs that would block future scoping.
- Forecasting assumes recent completed revenue and recurring costs continue until seasonality support exists.
- Recommendations expire after `recommendationTtlDays`.
- Mileage prediction, battery abuse, smoking violations, chargebacks, ratings, guest notes, weather, traffic, and Tesla telemetry remain future integrations until source data exists.

## Future Expansion

Future integrations should extend existing services rather than replace them:

- Tesla API telemetry for mileage, battery, charging behavior, and maintenance prediction.
- Turo API or other reservation-source APIs for richer demand and guest risk signals.
- Google Calendar, airport flight tracking, weather, traffic, SMS, email, push notifications, accounting, and multiple reservation sources.
- Future multi-tenant support should add `company_id`, `fleet_id`, and `reservation_source_id` filters at repository/service boundaries without changing the recommendation contract.

## Architectural Audit

- No SQL was added to Phase 5 services.
- No controller business logic was added.
- Fleet Command Center views render prepared recommendation arrays and do not calculate recommendations.
- Existing Phase 3 metrics are reused instead of recalculated from raw tables.
- Thresholds are configurable in `Config\DecisionSupport`.
- Recommendations are deterministic and explainable.
- Unsupported data does not produce synthetic recommendations.
- Unit tests cover pricing, maintenance prediction, forecasts, business insights, optimization, empty fleet, large fleet, cancelled trips, long-term rentals, zero revenue, dashboard serialization, and one-call dashboard aggregation.
- Repository-backed integration coverage verifies decision support against real `FleetIntelligenceRepository`, `RevenueService`, and `FleetStatisticsService` reads using measured test data.
