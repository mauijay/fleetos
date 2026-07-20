# Airport Operations

FleetOS supports focused HNL airport workflows for airport pickup delivery, airport returns, and Turo Access override reimbursement tracking.

## Turo Access

Under normal operation, Turo registers eligible vehicle plates with HNL. The plate reader opens the gate and no physical ticket should be pulled. If a guest or operator pulls a parking ticket, that ticket overrides Turo Access, paid parking begins, and the ticket must be paid before exit.

## Workflow Lifecycle

Airport movement workflow states include `not_started`, `preparing`, `staged`, `instructions_sent`, `picked_up`, `returned`, `vehicle_located`, `completed`, and `exception`.

Pickup workflows record garage, level, row, stall, parking entry time, access method, staged confirmation, guest instructions, pickup confirmation, and parking cost. Return workflows record guest-reported location, vehicle located confirmation, parking cost, and linked return checklist readiness.

## Guest Instructions

Pickup and return instructions are deterministic. They only include verified parking details. Both templates include the Turo Access warning: wait for the license-plate reader and do not pull a parking ticket.

## Checklist Integration

Airport staging can complete `airport_staging_completed` and `parking_location_recorded`. Sending verified instructions can complete `guest_pickup_instructions_confirmed` and `turo_access_instructions_confirmed`. Physical checks such as cleanliness, charge, damage-free condition, and key-card presence remain manual.

## Turo Access Override Incidents

Use **Parking Ticket Pulled** from the airport workflow when a ticket overrides Turo Access. Incidents track movement context, operator type, ticket number, parking amount, payment details, receipts, claim status, expected reimbursement, and final reimbursement outcome.

## Reimbursement Cap

The reimbursement cap is configured in `Config\\TuroAccess` as `reimbursementCapAmount`. The current documented cap is `$21.00`. FleetOS calculates expected reimbursement as the lower of paid parking and the configured cap, and tracks the remaining host cost separately.

## Airport Receipt Inbox

`/operations/airport/reimbursements` is the airport receipt inbox. Every airport receipt can be classified as `trip_reimbursement`, `airport_operations_expense`, `unresolved`, `non_business`, or `duplicate`. The inbox no longer assumes every receipt belongs to a guest trip or Turo claim.

Unmatched receipts can be entered with date, amount, ticket number, known vehicle, receipt file, and starting classification. The matching workspace shows trip reimbursement candidates and airport operations run candidates side by side. The operator must explicitly confirm either association; FleetOS does not auto-link receipts.

Receipt capture now supports JPEG, PNG, WebP, and PDF evidence through authenticated FleetOS forms. Receipt files are stored privately under `writable/uploads/airport-receipts`, with metadata in the shared `files` table. FleetOS stores a SHA-256 checksum to detect duplicate uploads by content rather than filename.

Receipt preview and download use controlled routes such as `/files/receipts/{file_id}`. Internal storage paths are not exposed. Mobile browsers can use the same file input for camera capture where supported.

Unmatched receipt capture allows the operator to save evidence before a trip or run is known. The receipt can later be manually linked to a selected airport workflow. Linking reuses an existing Turo Access override incident when one already exists for the selected workflow and ticket, or creates one when needed.

## Airport Operations Runs and Expenses

Airport operations runs represent real HNL work sessions such as delivering a fleet vehicle, recovering a returned vehicle, washing and restaging a vehicle, charging, inspecting, swapping, staging, or supporting several airport tasks during one visit. A run can exist without a guest trip.

Runs capture date, optional start/end times, chase vehicle type, optional fleet chase vehicle reference, chase vehicle description, operator, purpose, airport, start/end locations, mileage, notes, and status. Run activities can reference zero or more fleet vehicles, trips, and airport workflows.

Operations expenses capture parking, fuel, EV charging, car wash, supplies, tolls, airport access fees, mileage reimbursement, and other operating costs. Expenses may reference an airport operations run, receipt file, amount, date, vendor, payment method, business purpose, reimbursable flag, reimbursement source, and accounting status. These expenses are operating costs, not Turo receivables.

The quick action **Log Airport Run Expense** lets the operator create a run and save a photographed receipt without selecting a guest trip. Existing unmatched receipts can also be assigned to an existing run or to a new run from the receipt matching workspace.

Expense allocation is optional and supports equal split, manual amount, manual percentage, or unallocated. Allocations are for vehicle profitability analysis and cannot exceed the authoritative receipt total.

Split receipts are explicit. A split records original total, reimbursement portion, operations-expense portion, and remaining unclassified amount, and all portions must reconcile to the original receipt total.

Editing receipt metadata refreshes claim readiness and reimbursement math. Expected reimbursement remains separate from received cash.

## Financial Treatment

Expected reimbursement is not revenue and is not treated as received cash. Filing a claim does not mark it reimbursed. Reimbursed amount and date are recorded only when payment is actually received.

Airport operations expenses are operating expenses. They do not require a Turo claim and must not be counted as Turo receivables unless a separate valid reimbursement workflow explicitly links them. FleetOS prevents the same full receipt from being silently counted as both a trip reimbursement and an operations expense.

## Performance

Command Center loads airport workflow and reimbursement summaries only. Detailed workflow fields, exceptions, instructions, and receipt details load on airport operation pages.
