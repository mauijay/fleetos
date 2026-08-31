# Changelog

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
