# FleetOS Financial Methodology

FleetOS financial summaries are operational read models. They are not a general ledger, bank reconciliation, tax return, or statement of accounting profit.

## Company scope

The active company is resolved server-side. Every included source is filtered either by its direct `company_id` or through an authoritative vehicle/trip ownership relationship. Unmatched imported transactions and conflicting trip/vehicle relationships are excluded. Client-supplied company ownership is never used.

## Current-month summary

All realized and recorded inputs use a half-open `[from, to)` business-date range in `Pacific/Honolulu`.

- **Realized Operating Revenue:** signed normalized Turo rows with `event_class = operating_revenue`, recognized on `transaction_date`.
- **Realized Recoveries:** signed Turo reimbursement rows whose exact source transaction type is explicitly allowlisted and fixture-proven. The production allowlist is currently empty because neither the real fixture nor localhost normalized data contains a proven reimbursement label.
- **Recorded Operating Costs:** recorded generic operating expenses, completed maintenance, ended charging sessions, and established airport operations expenses.
- **Net Realized Operating Result:** realized operating revenue plus realized recoveries minus recorded operating costs.
- **Forecast Host Payout:** forecast trip-month allocation host payout, displayed separately and excluded from the realized result.

Recorded Operating Costs are operational records recognized by FleetOS. They are not proof of bank settlement.

## Source rules

Generic expenses require `status_code = recorded`, no archive timestamp, a positive amount, and `expense_date` in the period. Fleet-wide rows belong in the fleet total without invented vehicle allocation.

Maintenance requires a nondeleted, completed row with a positive `total_amount`; its date is `service_on`. Charging requires a nondeleted, ended session with positive `cost_amount`; its business date comes from `ended_at`.

`airport_operations_expenses` is the authoritative airport-cost source. Only `recorded`, `reimbursable`, and `reimbursed` statuses count. Legacy `airport_deliveries.parking_cost_amount` and workflow actual-parking fields are excluded without fallback or fuzzy deduplication.

Imported Turo fee, expense, adjustment, tax, cash-movement, other, and failed-payment-style rows are excluded. Signed operating-revenue rows already may contain deductions. Subtracting separate fee rows could count the same economics twice.

Accounting-parentheses are parsed as negative during new imports. Reporting compares persisted normalized values with the preserved raw amount and excludes a row when the values disagree; historical rows are not silently rewritten.

## Excluded domains

Loan monthly payments and insurance premiums are scheduled/informational obligations, not realized costs. Claim workflow amounts are not realized recoveries. Startup costs, acquisitions, purchase price, down payment, and financing are capital information outside the operating result.

## Identity and performance

Every activity has stable identity `source_type + source_id`. No amount/date/vendor/description matching is used. A fleet summary uses six bounded source queries: Turo transactions, forecast allocations, generic expenses, maintenance, charging, and airport expenses. Specialized rows are not copied into `operating_expenses`.

The vehicle report reuses that exact activity pipeline and adds one company-scoped roster query. Query count therefore remains fixed at seven regardless of fleet size. Vehicles with no period activity remain visible; deleted vehicles and vehicles from other companies do not.

## Vehicle-attributable results

Vehicle Financial Results is a supporting operational view, not a second financial formula. For each vehicle and period, FleetOS reports:

- **Attributable Realized Operating Revenue:** signed, authoritative Turo operating-revenue activity assigned directly or through a consistent normalized trip relationship.
- **Attributable Realized Recoveries:** validated realized recovery activity assigned to the vehicle. This remains zero while the production exact-label allowlist is empty.
- **Attributable Recorded Operating Costs:** vehicle-linked generic expenses plus completed maintenance, ended charging, and explicit airport expense allocations.
- **Attributable Net Realized Operating Result:** attributable realized operating revenue plus attributable realized recoveries minus attributable recorded operating costs.

Fleet-wide generic expenses are never spread across vehicles. Airport allocation uses only `airport_operations_expense_allocations.allocated_amount`; dates, amounts, filenames, trips, and locations are never fuzzy-matched to infer a vehicle. Multiple explicit allocations are honored in cents. The residual between the authoritative airport source amount and its valid explicit allocations remains **Fleet-wide / Unallocated Costs**. If allocations exceed their source amount, they are excluded from vehicle attribution and surfaced diagnostically rather than fabricated or prorated.

For a company and period, FleetOS enforces these read-model reconciliation invariants:

```text
sum(vehicle attributable recorded operating costs)
  + fleet-wide / unallocated costs
  = fleet recorded operating costs

sum(vehicle attributable realized operating revenue)
  + truly unallocated realized operating revenue
  = fleet realized operating revenue
```

Imported rows with unresolved or conflicting ownership stay excluded from company reporting; they do not become a mystery revenue bucket. All arithmetic is performed in integer cents and displayed to two decimal places.

These results deliberately omit fleet-wide cost allocation, debt payments, insurance obligations, capital activity, forecast payout, and claim estimates. They must not be read as vehicle profit, ROI, net income, or complete accounting profitability. Trip-level profitability remains deferred.

## Historical methodology change

Historical totals may change because scheduled obligations, legacy airport fields, incomplete costs, and misleading allocation-based realized revenue are removed, while authoritative generic/airport costs are included. Source history is not rewritten. A combined specialized expense hub and trip-level profitability remain deferred.
