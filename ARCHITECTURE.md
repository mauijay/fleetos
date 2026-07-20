# FleetOS Architecture Guide

## Purpose

FleetOS is a professional fleet operating system for managing a Tesla-based rental fleet.

The application is currently built for 808biz, Inc., but every architectural decision should preserve the possibility of supporting multiple companies, fleets, reservation sources, and vehicle types in the future.

FleetOS is not just a website. It is a long-term fleet management platform.

---

## Core Principles

### 1. Database First

The database is the foundation of FleetOS.

All major features must begin with a clear data model, migrations, relationships, indexes, and seed strategy before UI work begins.

### 2. Single Source of Truth

Every business rule must exist in exactly one place.

Examples:

- Revenue calculations belong in revenue services.
- Utilization calculations belong in fleet intelligence services.
- Trip month allocation belongs in the allocation engine.
- Import validation belongs in import validators.
- UI components display data but do not calculate it.

### 3. DRY

Do not duplicate:

- SQL
- validation rules
- business logic
- lookup values
- UI components
- formatting rules
- status definitions

If logic appears twice, extract it.

### 4. No Fake Data

FleetOS must never invent operational, financial, or analytical data.

Future integrations may return:

- `null`
- empty arrays
- placeholder states
- "not available yet"

but never fake metrics.

### 5. Explainable Intelligence

All recommendations must be deterministic, measurable, and explainable.

Every recommendation must include:

- title
- category
- priority
- confidence
- reason
- supporting metrics
- suggested action
- source service
- generated date
- expiration date

FleetOS does not guess.

---

## Technology Stack

Backend:

- PHP 8.4+
- CodeIgniter 4
- CodeIgniter Shield
- CodeIgniter Settings
- Composer

Frontend:

- Tailwind CSS v4
- Vite
- Minimal JavaScript
- Alpine.js only when justified

Database:

- MySQL 8+

Development:

- VS Code
- Git
- GitHub
- Windows development environment
- Docker-ready structure where practical

---

## Code Standards

- PSR-12
- Strict typing where practical
- Thin controllers
- Service layer for business logic
- Repository layer for database reads/writes
- DTOs or structured arrays for service responses
- Migrations for all database changes
- Seeders for lookup/default data
- Tests for critical business rules
- Clear documentation for every phase

---

## Application Layers

### Controllers

Controllers orchestrate requests.

Controllers may:

- load services
- validate request input
- call service methods
- pass view models to views
- return responses

Controllers must not:

- contain SQL
- calculate business metrics
- duplicate service logic
- directly manipulate reporting calculations

### Services

Services answer business questions.

Services contain business rules, calculations, orchestration, and decision logic.

Examples:

- RevenueService
- FleetStatisticsService
- FleetCommandService
- PricingRecommendationService
- TripMonthAllocationService

### Repositories

Repositories retrieve or persist data.

Repositories may contain query-builder logic.

Repositories should not contain business decision logic.

### Views

Views display data.

Views must not:

- run SQL
- calculate business metrics
- contain business rules
- determine status logic

### Components

Reusable UI components should be preferred over repeated markup.

Examples:

- MetricCard
- VehicleCard
- StatusBadge
- TaskCard
- AlertPanel
- TimelineCard

---

## Database Standards

### Normalization

The database should follow normalized relational design.

Avoid unnecessary duplication.

Shared tables should be reused where appropriate.

Examples:

- addresses
- cities
- states
- phone_numbers
- images
- files
- notes
- audit_logs
- lookup tables

### Foreign Keys

Use foreign keys wherever relationships are required.

Define indexes for:

- foreign keys
- lookup columns
- dates used in reporting
- reservation/source IDs
- vehicle IDs
- company/fleet future-scope columns

### No ENUM Columns

Avoid MySQL `ENUM`.

Use integer foreign keys to lookup tables instead.

### Soft Deletes

Use soft deletes where records may need to be preserved historically.

Financial, import, audit, and reporting records should generally not be hard deleted.

### Auditability

Important changes should be audit logged.

Audit logs should capture:

- actor
- action
- table
- record ID
- before state
- after state
- timestamp

---

## Naming Conventions

Use clear, consistent, professional naming.

Prefer:

- `fleet_vehicles`
- `turo_trips_normalized`
- `trip_month_allocations`
- `reservation_sources`
- `vehicle_statuses`

Avoid vague names like:

- `data`
- `stuff`
- `misc`
- `temp`
- `thing`

Use `snake_case` for database columns.

Use descriptive service names ending in `Service`.

Use descriptive repository names ending in `Repository`.

---

## Import Architecture

All imports must follow this pattern:

Upload or read source file

↓

Validate headers

↓

Create import batch

↓

Store raw rows

↓

Normalize rows

↓

Generate allocation rows

↓

Log warnings/errors

↓

Audit import activity

↓

Archive source reference

Rules:

- Never import directly into final reporting tables.
- Never silently discard bad rows.
- Duplicate imports must be detected.
- Failed rows must not corrupt valid rows.
- Raw source data must remain preserved.
- Normalization must be repeatable.
- Allocation must be repeatable.

---

## Reporting Architecture

FleetOS separates:

- financial reporting
- operational reporting
- forecast reporting
- utilization reporting

### Financial Reporting

Financial reports are based on recognized revenue, completed trips, cancellation payouts, expenses, loans, insurance, startup costs, and amortization.

### Operational Reporting

Operational reports are based on trip days, vehicle availability, reservations, maintenance, claims, cleaning, and task status.

### Forecast Reporting

Forecast reports include booked and in-progress trips when the source data provides expected payout.

Forecast values must be labeled as forecast, not actual.

### Trip Month Allocation

Long trips must be split across months.

A 30-day trip spanning June and July must produce allocation rows for both months.

Do not assign an entire long trip to one reporting month.

---

## Recommendation Engine Standards

Recommendations must be:

- deterministic
- explainable
- based on real data
- confidence scored
- traceable to source services

Recommendations must not be based on invented data.

Thresholds should be configurable where appropriate.

Examples:

- high utilization threshold
- low utilization threshold
- registration warning days
- insurance warning days
- battery warning threshold
- pricing recommendation thresholds
- maintenance intervals

---

## SaaS Readiness Principles

FleetOS currently supports a single fleet.

However, avoid design choices that would prevent future SaaS expansion.

Do not hard-code:

- 808biz
- Jay
- Spaceship
- Turo as the only reservation source
- one company
- one fleet
- one user type

Prefer flexible concepts:

- company
- fleet
- vehicle
- reservation source
- platform
- role
- permission

Do not prematurely add unnecessary SaaS complexity, but keep future multi-company support possible.

---

## UI Standards

FleetOS should feel like professional operations software.

Design goals:

- clean
- minimal
- fast
- responsive
- dark-mode friendly
- accessible
- consistent
- Tesla-inspired but not Tesla-branded

Every module should use consistent:

- page headers
- card styles
- tables
- buttons
- badges
- spacing
- typography
- form layouts

---

## Fleet Command Center Philosophy

The Fleet Command Center is the operational homepage.

It should answer:

> What needs my attention today?

It should prioritize:

- pickups
- returns
- cleaning
- charging
- maintenance
- registration
- insurance
- claims
- fleet health
- urgent tasks

The Command Center should not be overloaded with deep financial analysis.

Executive reporting belongs in reporting and intelligence views.

---

## Testing Standards

Tests should cover:

- imports
- validation
- normalization
- trip month allocation
- revenue calculations
- utilization calculations
- recommendation rules
- empty datasets
- edge cases
- cancelled trips
- in-progress trips
- long-term rentals

Every major phase should end with:

- tests passing
- diagnostics clean
- architectural audit complete
- documentation updated
- commit
- tag
- push

---

## Release Process

Before each release:

1. Run tests.
2. Run diagnostics.
3. Perform architecture audit.
4. Review `git status`.
5. Review `git diff --stat`.
6. Commit with a meaningful message.
7. Tag the release.
8. Push commit.
9. Push tags.
10. Verify `git log --oneline --decorate -5`.

Version pattern:

- `v0.1.0-foundation`
- `v0.2.0-trip-import-engine`
- `v0.3.0-fleet-intelligence`
- `v0.4.0-fleet-command-center`
- `v0.5.0-decision-support`

---

## Documentation Standards

Each major phase should have a document in `/docs`.

Examples:

- `phase1-foundation.md`
- `phase2-turo-import-engine.md`
- `phase3-fleet-intelligence.md`
- `phase4-fleet-command-center.md`
- `phase5-decision-support.md`

Documentation should explain:

- purpose
- architecture
- public methods
- dependencies
- assumptions
- known gaps
- future improvements
- testing completed
- audit notes

---

## Non-Negotiables

FleetOS must not:

- fake data
- hide import errors
- duplicate business logic
- put SQL in views
- put calculations in controllers
- hard-code SaaS-limiting assumptions
- overwrite raw imported data
- silently discard bad data
- assign long trips to only one month
- use enums where lookup tables are better

---

## Long-Term Vision

FleetOS should become a true fleet operating platform.

Possible future integrations:

- Tesla API
- Turo API, if available
- direct bookings
- Google Calendar
- Google Maps
- airport flight tracking
- weather
- traffic
- SMS
- email
- accounting
- payment systems
- multi-fleet SaaS support

The architecture should allow these integrations to extend FleetOS without replacing the foundation.

---

## Current Philosophy

Build for Jay first.

Design so FleetOS can grow beyond Jay later.

Keep the product useful today and expandable tomorrow.

## architecture

Repository
fleetos

Internal Project
FleetOS

Platform/Core
FleetOS Core

Public Product
Orbit

Company
808biz, Inc.

Marketing Site
go808.com

Application
orbit.go808.com
