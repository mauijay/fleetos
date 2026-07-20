# GO808 FleetOS Phase 1 Database Foundation

## Proposed Schema

This project starts database-first. CodeIgniter Shield owns authentication tables and flows, and FleetOS application tables reference Shield users only where business records need user attribution.

Shared foundation tables:

- `lookup_types`, `lookup_values`: reusable typed lookup data for generic statuses and categories without database enum columns.
- `companies`: fleet owner, vendors, lenders, insurers, and future organization records.
- `cities`, `states`, `addresses`, `company_addresses`, `vehicle_addresses`: normalized address storage with domain pivots. Addresses belong to cities; cities belong to states, so state/country data is not repeated on each address.
- `phone_numbers`, `company_phone_numbers`: reusable phone records with ownership-specific classification on the pivot.
- `images`, `vehicle_images`: reusable image metadata plus vehicle pivot details.
- `files`, `vehicle_files`, `damage_claim_files`, `maintenance_log_files`: reusable document metadata plus domain pivots.
- `notes`, `vehicle_notes`, `damage_claim_notes`, `maintenance_log_notes`: reusable note content plus domain pivots.
- `audit_logs`: event trail for application records, with JSON old/new values and Shield user attribution.

Fleet core tables:

- `vehicle_makes`, `vehicle_models`, `vehicle_body_styles`, `vehicle_colors`, `vehicle_features`: normalized vehicle catalog tables.
- `vehicle_trim_levels`, `vehicle_drivetrains`, `vehicle_statuses`: vehicle operational lookup tables.
- `vehicle_specs`: normalized model/year/body/color/battery/seating records.
- `fleet_vehicles`: individual fleet units linked to company, spec, trim, drivetrain, and status.
- `fleet_vehicle_features`: vehicle-level feature assignments such as FSD and one-year free Supercharging.
- `vehicle_turo_listings`: Turo listing metadata per fleet vehicle.
- `vehicle_turo_listing_audits`: audit trail for explicit Turo vehicle mapping and remapping actions.
- `turo_import_reprocess_attempts`: audit trail for historical import issue reconciliation attempts.
- `lenders`, `loans`, `insurance_policies`, `registrations`: ownership, financing, insurance, and compliance records.
- `startup_costs`, `maintenance_logs`, `damage_claims`, `charging_sessions`, `airports`, `airport_deliveries`: operations and cost tracking.
- `turo_import_batches`, `turo_trip_raw`, `turo_transaction_raw`, `turo_trips_normalized`, `trip_month_allocations`: import, normalization, and reporting foundation.

## ERD-Style Relationship Summary

- `companies` has many `fleet_vehicles`.
- `companies` has many `company_addresses` and `company_phone_numbers`.
- `vehicle_models` belongs to `vehicle_makes`.
- `vehicle_specs` belongs to `vehicle_models`, `vehicle_body_styles`, and two `vehicle_colors` rows for exterior and interior colors.
- `fleet_vehicles` belongs to `companies`, `vehicle_specs`, `vehicle_trim_levels`, `vehicle_drivetrains`, and `vehicle_statuses`.
- `fleet_vehicles` has many `fleet_vehicle_features`, `vehicle_images`, `vehicle_files`, `vehicle_notes`, `vehicle_turo_listings`, `loans`, `insurance_policies`, `registrations`, `startup_costs`, `maintenance_logs`, `damage_claims`, `charging_sessions`, `airport_deliveries`, and `turo_trips_normalized`.
- `addresses` belongs to `cities`; `cities` belongs to `states`; vehicle/company ownership is handled through pivot tables.
- `lenders` belongs to `companies`; `loans` belongs to `lenders` and `fleet_vehicles`.
- `insurance_policies` belongs to an insurer `company` and optionally to `fleet_vehicles`.
- `damage_claims`, `charging_sessions`, and `airport_deliveries` belong to `fleet_vehicles` and can link to `turo_trips_normalized` after the reporting tables exist.
- `airport_deliveries` belongs to `airports`.
- `turo_import_batches` has many raw trips and raw transactions.
- `turo_trips_normalized` optionally belongs to a raw trip import and belongs to a vehicle when matched.
- `trip_month_allocations` belongs to `turo_trips_normalized` and stores month-level revenue/day allocation for reporting.

## Migration Plan

1. Create shared foundation tables and generic lookup storage.
2. Create company, vehicle catalog, vehicle lookup, vehicle spec, fleet vehicle, and vehicle pivot tables.
3. Create financial and operational tables with trip-reference columns and airport lookup data.
4. Create Turo import, normalized trip, transaction, and month allocation tables.
5. Add deferred operational foreign keys to `turo_trips_normalized` once the reporting tables exist.
6. Let Shield migrations manage auth tables; do not duplicate users/password/session logic.
7. Use CodeIgniter Settings through Shield's installed dependency for future app settings.
8. Use Tailwind CSS v4 and Vite for future UI work; Phase 1 does not build public pages yet.

## Seeder Plan

1. `LookupSeeder`: creates generic lookup values, vehicle trims, drivetrains, statuses, Hawaii cities, and airport lookup data.
2. `FleetVehicleSeeder`: creates GO808 company, Tesla vehicle catalog records, normalized specs, current Spaceship fleet vehicles, and vehicle feature assignments.
3. Future seeders: lenders, airport locations, Settings defaults, Shield admin user, and Turo import sample fixtures.

## Folder Structure

- `app/Database/Migrations`: database schema migrations.
- `app/Database/Seeds`: seeders for lookup/reference data and safe initial records.
- `app/Entities`: future typed domain entities.
- `app/Models`: persistence models only.
- `app/Services`: business workflows, import normalization, reporting allocation services.
- `app/Controllers`: thin HTTP controllers.
- `app/Validation`: reusable validation rules.
- `app/Views`: rendering only; no business logic.
- `resources/css`, `resources/js`: future Tailwind/Vite assets.

## GitHub Setup Steps

Current repository remote is configured as:

```bash
git remote add origin https://github.com/mauijay/turofleet.git
git push -u origin main
```

The branch is tracking `origin/main`. Continue committing Phase 1 foundation work to `main` unless a feature branch workflow is introduced.

## Reporting Notes

`trip_month_allocations` is intentionally first-class. Every normalized trip should create one or more allocation rows by calendar month. This supports long-trip revenue splits, forecast revenue, ADR, RevPAD, utilization, and comparisons such as Premium vs Base trims without relying on ad hoc report-time date math.
