# GO808 FleetOS Phase 2 Turo Import Engine

## Purpose

Phase 2 starts the backend service layer for importing Turo operational data. This phase is intentionally backend-only: no UI pages, views, or controllers are part of the import workflow yet.

The first production import slice covers Turo trip CSV files. It preserves source data, validates each row, normalizes trip records, regenerates month-level reporting allocations, records audit history, and stores import issues in a format a non-programmer can review.

## Scope

Implemented in this phase:

- Trip CSV import command: `php spark turo:import:trips <file> [--user USER_ID]`.
- CSV streaming and header normalization.
- Row-level trip validation.
- Raw row preservation in `turo_trip_raw`.
- Normalized trip upsert into `turo_trips_normalized`.
- Calendar-month allocation regeneration in `trip_month_allocations`.
- Import batch tracking through `turo_import_batches`.
- Row issue tracking through `turo_import_errors`.
- Audit logging for import, create, update, and allocation activity.
- Focused unit tests for validation, normalization, and allocation behavior.

Deferred to later Phase 2 increments:

- Turo transaction CSV import.
- UI review screens for batches and row issues.
- Operator-driven import retry tools.
- Full database integration fixtures for mixed valid and invalid files.

## Architecture

Phase 2 follows a service-first backend design.

Commands are thin CLI entry points:

- `app/Commands/TuroImportTrips.php`: validates CLI input, calls the service, and prints a concise result.

Services own workflow and business rules:

- `TuroTripImportService`: orchestrates file hashing, duplicate rejection, batch creation, validation, raw row storage, normalization, allocation replacement, issue recording, and audit logging.
- `TuroCsvReader`: streams CSV rows and normalizes headers to snake_case aliases.
- `TuroTripCsvValidator`: validates required fields, date ranges, money values, and status presence.
- `TuroTripNormalizer`: maps raw CSV data into normalized trip records.
- `TuroVehicleMatcher`: deterministically maps a Turo row to a fleet vehicle.
- `TripMonthAllocationService`: splits trip days and money into calendar-month reporting rows.
- `TuroImportAuditService`: centralizes audit event recording for import actions.

Repositories own persistence:

- `TuroImportBatchRepository`
- `TuroRawTripRepository`
- `TuroNormalizedTripRepository`
- `TripMonthAllocationRepository`
- `TuroImportErrorRepository`
- `FleetVehicleRepository`
- `LookupRepository`
- `AuditLogRepository`

DTOs carry import data between layers:

- `CsvRowResult`
- `ImportResult`
- `RawTripRow`
- `NormalizedTripData`
- `TripMonthAllocationData`
- `ValidationIssue`

## Import Workflow

1. The command receives a CSV path and optional Shield user id.
2. `TuroTripImportService` hashes the file with SHA-256.
3. If the same source hash already exists in `turo_import_batches`, the import is rejected before any rows are processed.
4. A processing batch is created.
5. The CSV reader streams rows one at a time.
6. Each row is validated before raw trip insertion.
7. Rows with validation errors are recorded in `turo_import_errors` and skipped.
8. Valid rows are saved to `turo_trip_raw` with the full JSON payload and row hash.
9. The normalizer maps status, dates, money values, billable days, reservation ids, guest names, and vehicle references.
10. The normalized trip is inserted or updated by `turo_trip_id`.
11. Month allocations are regenerated for the normalized trip.
12. Audit logs are written for the import activity.
13. The batch is marked completed, or failed if an exception interrupts processing.

## Idempotency and Duplicate Handling

File-level idempotency is enforced by `turo_import_batches.source_hash`. Importing the same CSV file twice is rejected safely before row processing.

Trip-level repeatability is enforced by `turo_trips_normalized.turo_trip_id`. A later file containing the same trip id updates the existing normalized trip instead of creating a duplicate reporting trip.

Duplicate trip ids inside the same CSV are handled before database writes. The first valid row is imported; later rows with the same trip id are skipped and recorded as warnings with the original row number.

Raw row hashes are also stored in `turo_trip_raw` and constrained within a batch to protect against duplicate raw rows.

## Row Failure Behavior

Failed validation rows do not block good rows. They are recorded in `turo_import_errors` with:

- Batch id
- Severity
- Row number
- Error code
- Field name
- Human-readable message
- Raw JSON payload

Valid row persistence runs inside a transaction. If raw insertion, normalization, allocation replacement, or audit logging fails for a valid row, that row's database work is rolled back and the batch is marked failed.

Month allocation replacement is also atomic at the repository level. Existing allocations are deleted and new allocations inserted in one transaction so reporting rows are not left half-regenerated.

## Raw Data Preservation

Raw data is preserved in two places depending on row outcome:

- Valid rows are stored in `turo_trip_raw.raw_payload`.
- Invalid or skipped rows are stored in `turo_import_errors.raw_payload`.

This lets future UI screens show operators the original CSV row without needing to reopen the uploaded file.

## Normalization Rules

Trip status mapping:

- Status containing `cancel` with host payout greater than zero maps to `canceled_host_payout`.
- Status containing `cancel` with zero host payout maps to `canceled_zero_payout`.
- Status containing `complete` maps to `completed`.
- Status containing `progress` or `active` maps to `in_progress`.
- Missing status imports as `booked` with a warning.

Money values are normalized to fixed two-decimal strings. Date values are parsed into database datetime strings. Required dates must already have passed validation; the normalizer does not substitute the current time.

Forecast behavior:

- `booked` trips are marked forecast.
- completed, in-progress, and canceled trips are not forecast.

Cancellation behavior:

- Canceled trips with host payout retain billable days.
- Canceled trips with zero host payout are normalized to zero billable days.

## Vehicle Matching

Vehicle matching is deterministic and ordered:

1. Match by active `vehicle_turo_listings.turo_vehicle_id`.
2. If no Turo vehicle id match exists, match by `fleet_vehicles.fleet_code`.
3. If neither match exists, import the trip with `fleet_vehicle_id = null` and record a warning.

The warning tells the operator to check Vehicle ID, Turo Vehicle ID, or Fleet Code against the fleet vehicle record.

## Month Allocation Rules

Every normalized trip regenerates its month allocation rows.

Allocation behavior:

- Split by calendar month boundaries.
- Allocate trip days, billable days, gross revenue, host payout, delivery fee, and reimbursement proportionally by seconds in each month segment.
- Put rounding remainder on the final segment so totals match the normalized trip.
- Support long trips spanning multiple months and calendar years.

This keeps reporting queries simple and avoids recalculating month splits at report time.

## Import Error UX

Import errors are written for operators, not programmers.

Examples:

- Missing trip id: explains that the row needs a trip or reservation id.
- Invalid money: shows the received value and expected examples such as `100.00` or `$100.00`.
- Invalid date: shows the received value and an expected date/time example.
- Unmatched vehicle: explains which vehicle fields to check.
- Duplicate trip in file: shows the earlier row number that was imported.

## Audit Logging

Import activity is written to `audit_logs` with lookup-driven action ids.

Current audit events include:

- Import batch creation.
- Normalized trip creation.
- Normalized trip update.
- Month allocation regeneration summary.

When the command receives `--user USER_ID`, that Shield user id is attached to the audit records.

## Tests

Focused unit tests currently cover:

- Valid row validation.
- Missing trip id validation.
- Operator-friendly invalid money message.
- Canceled trip with host payout.
- Canceled trip with zero payout.
- In-progress trip status mapping.
- Unmapped vehicle normalization.
- Two-month allocation with rounding remainder.
- Long trip allocation across calendar-year month boundaries.

Audit validation performed before commit:

```bash
find app/Commands app/DTOs app/Repositories app/Services app/Validation app/Database/Migrations app/Database/Seeds tests/unit -name '*.php' -print0 | xargs -0 -n1 php -l
vendor/bin/phpunit tests/unit/TripMonthAllocationServiceTest.php tests/unit/TuroTripCsvValidatorTest.php tests/unit/TuroTripNormalizerTest.php
php spark turo:import:trips ../writable/uploads/phase2_audit_smoke.csv
php spark turo:import:trips ../writable/uploads/phase2_audit_smoke.csv
```

Expected focused test result:

```text
OK (9 tests, 33 assertions)
```

Smoke import result:

```text
Rows read: 2
Raw rows created: 1
Trips normalized: 1
Allocation rows created: 1
Row issues: 2
```

The second smoke import should reject the duplicate source hash and exit with an error.

## Command Usage

Run from the project root:

```bash
php spark turo:import:trips ../writable/uploads/trips.csv
```

Run with audit attribution:

```bash
php spark turo:import:trips ../writable/uploads/trips.csv --user 1
```

Spark executes from the `public` directory, so paths may need to be relative to `public` unless a future command enhancement resolves paths from the project root.

## Current Status

Phase 2 currently provides a production-oriented backend foundation for trip imports. It is ready for commit after review.

Recommended next backend increments:

1. Add Turo transaction import using the same command-service-repository pattern.
2. Add database integration tests with fixture CSV files.
3. Add batch issue review workflows after backend import behavior is stable.
