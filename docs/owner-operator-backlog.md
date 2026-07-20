# FleetOS Owner-Operator Backlog

FleetOS is now optimized for one fleet owner operating a real Turo fleet today. Productization, multi-company features, billing, subscriptions, white-label behavior, and speculative SaaS architecture are intentionally out of scope.

## P0 - Saves Time Immediately

| Order | Item                                         | Effort   | Expected Time Savings                            | Business Value                                                                                   | Dependencies                                                                     |
| ----- | -------------------------------------------- | -------- | ------------------------------------------------ | ------------------------------------------------------------------------------------------------ | -------------------------------------------------------------------------------- |
| 1     | Browser-based Turo trips CSV import          | Small    | 10-20 minutes per import plus fewer missed steps | Removes command-line import work and makes FleetOS the daily source of truth faster              | Existing `TuroTripImportService`, upload route, import result UI                 |
| 2     | Import issue review screen                   | Complete | 15-30 minutes per dirty CSV                      | Shows exactly which rows need cleanup without opening logs or re-reading spreadsheets            | Import batches and `turo_import_errors` data                                     |
| 3     | Vehicle matching cleanup queue               | Complete | 10-20 minutes per import with unmatched vehicles | Converts unmatched Turo vehicle warnings into a fix-once workflow                                | Import issue review, fleet vehicle/listing data                                  |
| 4     | Safe reprocessing for unmatched Turo trips   | Complete | 10-20 minutes per historical cleanup session     | Reconciles prior unmapped trips without duplicate trips or duplicate allocations                 | Vehicle matching cleanup, existing import pipeline                               |
| 5     | Command Center v2 daily operations dashboard | Complete | 30-60 minutes daily                              | Makes FleetOS the morning operating screen for pickups, returns, turnarounds, and actions        | Vehicle availability, task, health, import, mapping, and reconciliation services |
| 6     | Daily pickup/return checklist workflow       | Complete | 20-30 minutes daily                              | Turns awareness into execution for handoffs, returns, cleaning, charge, photos, and disposition  | Command Center v2, normalized trips                                              |
| 7     | Airport delivery and return workflow         | Complete | 10-20 minutes per airport movement               | Keeps HNL staging, guest instructions, recovery, parking costs, and exceptions attached to trips | Airport deliveries, movement checklists                                          |
| 8     | HNL Turo Access reimbursement workflow       | Complete | 10-20 minutes per paid ticket incident           | Prevents unidentified receipts and tracks claim-ready reimbursement opportunities                | Airport workflow, Turo Access incidents                                          |
| 9     | Today action quick links from Command Center | Small    | 5-10 minutes daily                               | Reduces clicks from dashboard insight to operational action                                      | Existing mission cards and routes                                                |

## P1 - Improves Operations

| Order | Item                                            | Effort | Expected Time Savings                   | Business Value                                                                    | Dependencies                   |
| ----- | ----------------------------------------------- | ------ | --------------------------------------- | --------------------------------------------------------------------------------- | ------------------------------ |
| 5     | Daily pickup/return checklist                   | Medium | 20-30 minutes daily                     | Standardizes handoffs, cleaning, charging, airport steps, and reduces missed work | Today mission data             |
| 6     | Maintenance and registration data entry screens | Medium | 10-15 minutes per update                | Eliminates spreadsheet tracking for recurring compliance and service tasks        | Existing fleet health services |
| 7     | Airport delivery/return workflow                | Medium | 10-20 minutes per airport trip          | Keeps parking cost, scheduled time, and task status in one place                  | Airport delivery read model    |
| 8     | Claim follow-up tracker                         | Medium | 10 minutes daily when claims are active | Keeps reimbursement and repair follow-up visible until closed                     | Existing open claims queries   |

## P2 - Improves Reporting

| Order | Item                                              | Effort       | Expected Time Savings               | Business Value                                                              | Dependencies                           |
| ----- | ------------------------------------------------- | ------------ | ----------------------------------- | --------------------------------------------------------------------------- | -------------------------------------- |
| 9     | Revenue drilldown by vehicle and month            | Medium       | 20-40 minutes per reporting session | Makes underperforming vehicles and monthly revenue shifts obvious           | Month allocation data                  |
| 10    | Expense entry/import for charging and maintenance | Medium/Large | 30-60 minutes per month             | Improves operating profit accuracy without manual spreadsheet totals        | Maintenance/charging tables            |
| 11    | Forecast vs actual monthly report                 | Medium       | 15-30 minutes weekly                | Supports pricing and utilization decisions with booked vs completed revenue | Current revenue service                |
| 12    | Fleet ROI report by vehicle                       | Medium       | 20-30 minutes monthly               | Shows which cars should be retained, improved, or sold                      | Startup costs, loans, lifetime revenue |

## P3 - Nice-to-Have

| Order | Item                       | Effort | Expected Time Savings                 | Business Value                                     | Dependencies                    |
| ----- | -------------------------- | ------ | ------------------------------------- | -------------------------------------------------- | ------------------------------- |
| 13    | Dashboard caching          | Small  | Faster page loads during repeated use | Improves daily feel once data volume grows         | Stable dashboard query patterns |
| 14    | CSV import history filters | Small  | 5 minutes when auditing imports       | Easier lookup of past import batches               | Import issue review screen      |
| 15    | Calendar export            | Medium | 5-10 minutes daily                    | Helps view trips in an external calendar           | Reliable reservation timeline   |
| 16    | Future API integrations    | Large  | Unknown until connected               | Useful only after core manual workflows are solved | Stable operational workflows    |

## Recommended Implementation Order

1. Browser-based Turo trips CSV import.
2. Import issue review screen.
3. Vehicle matching cleanup queue.
4. Safe reprocessing for unmatched Turo trips.
5. Command Center v2 daily operations dashboard.
6. Daily pickup/return checklist workflow.
7. Today action quick links from Command Center.
8. Maintenance and registration data entry screens.
9. Airport delivery/return workflow.
10. Claim follow-up tracker.

## Current P0 Implementation

Browser-based Turo trips CSV import is complete. It saves time immediately, reduces repeated terminal work, and moves trip/revenue updates closer to the Command Center workflow without adding speculative SaaS features.

Import issue review is complete. FleetOS now lists unresolved Turo import warnings and errors, shows source-row values, supports filters, and lets the operator resolve or reopen each issue with an audit-friendly note and timestamp.

Vehicle matching cleanup is complete. FleetOS now groups unresolved `vehicle_unmatched` warnings by unique Turo vehicle ID, suggests deterministic FleetOS matches, saves mappings into `vehicle_turo_listings`, and lets the operator bulk-resolve related mapping issues after confirming the saved association.

Safe reprocessing is complete. FleetOS now previews historical `vehicle_unmatched` rows after a mapping is saved, classifies eligibility, reprocesses ready rows through the shared Turo import row pipeline, treats equivalent existing trips as reconciled, leaves conflicts open, and protects month allocations by replacing allocations for the normalized trip instead of adding duplicates.

Command Center v2 is complete. FleetOS now opens with a deterministic Morning Briefing, a 9-vehicle movement board, chronological daily timeline, immediate attention queue, daily fleet counts, operational action queue, secondary financial snapshot, and explicit data-honesty notes for battery, cleaning, locations, and travel-time inputs that are not yet reliably captured.

Daily pickup and return checklists are complete. FleetOS now creates one idempotent checklist per trip movement, tracks required and critical pickup/return actions, supports not-applicable items, records manual completion source and timestamps, requires return disposition before return completion, and links movement board/timeline entries directly to the correct checklist.

Airport delivery and return workflows are complete. FleetOS now creates HNL airport movement records for linked airport trips, records staging details, generates verified guest instructions, tracks pickup/return/recovery milestones, links to movement checklists, records parking costs, and surfaces airport work in Command Center.

HNL Turo Access reimbursement tracking is complete. FleetOS now records Turo Access override incidents when a parking ticket is pulled, ties receipts to trips, calculates expected reimbursement using a configurable $21 cap, tracks claim filing and reimbursement state, supports unmatched historical receipts, and surfaces reimbursement work in Command Center.

Airport receipt capture and classification is complete. FleetOS now uploads receipt evidence into the shared private file system, stores receipt metadata, previews evidence through controlled routes, supports unmatched receipt capture, classifies receipts into trip reimbursement, airport operations expense, unresolved, non-business, or duplicate buckets, links reimbursement receipts to airport workflows manually, and records chase-vehicle airport run expenses without inventing a guest trip association.
