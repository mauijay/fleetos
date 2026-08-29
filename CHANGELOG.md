# Changelog

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
