# Operating Expenses Capture and Visibility

Slice 3B.3A adds a company-scoped generic operating-expense register and receipt inbox. The operator may upload evidence first and classify it later, or record an expense immediately with an optional receipt. Required expense facts are date, positive decimal amount, and a controlled category. Vehicle and trip associations are optional; trip selection derives and validates the vehicle within the active company.

The generic categories cover cleaning/detailing, supplies/consumables, fuel, non-airport parking/tolls, towing/roadside, registration fees, fleet software/services, and another ordinary operating expense with an explanation. Airport, maintenance, charging, startup/acquisition, financing, insurance, and imported Turo costs remain in their specialized domains.

Receipts live under `writable/uploads/operating-expense-receipts` and use the shared private evidence storage controls: random server paths, MIME allowlisting plus `finfo` verification, size limits, SHA-256, containment checks, safe response names, and no-cache/nosniff responses. Browser access begins at the company-owned `operating_expense_receipts` parent through `/operations/expenses/receipts/{receipt_id}/file`; no raw file-ID route exists.

Corrections retain audit old/new values. Material corrections require a reason. Expenses are archived and restored rather than hard-deleted. The Command Center shows `Expenses to classify` only while unresolved generic receipt-inbox rows exist.

`Recorded operating expenses` is deliberately incomplete fleet-cost reporting in 3B.3A. Specialized Airport, Maintenance, Charging, financing, insurance, acquisition, and Turo-import financial sources are not unified or included in this total. Full source integration and double-count prevention are deferred to 3B.3B.
