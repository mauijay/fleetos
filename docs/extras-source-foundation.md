# Extras source, mapping, and activity foundation

Slice A establishes source-backed Extra activity without adding a performance report or changing FleetOS financial totals.

## Source and privacy contract

The operator runs `tools/turo-extras-exporter.js` in browser developer tools while already authenticated on `https://turo.com`. The helper calls Turo's same-origin reservation-detail endpoint sequentially and downloads a local `fleetos-turo-extras-v1` JSON file. It never reads or emits cookies, authorization headers, session tokens, guest contact data, licenses, messages, or unrelated reservation data. FleetOS does not log into Turo and has no scraping proxy.

Turo may encode stable `extraId`, `reservationStateExtraId`, and `reservationStateId` values as JSON numbers. The browser exporter accepts only safe integer identifiers or digit strings and normalizes them to the existing sanitized string representation without deriving identity from labels, descriptions, or prices. It maps only explicitly supported commercial fields into the strict FleetOS schema.

In the current selected-Extra response, `extraType.label` supplies the source label and `extraType.value` supplies the source type code; `extraValue` may be absent. The selected historical price and currency come from `priceWithCurrency.amount` and `priceWithCurrency.currencyCode`. Explicit `quantity` is preserved, including values greater than one, while omitted quantity remains `NULL`-compatible. `extraType.priceRecommendationSummary` is not a selected price and is never used. Older explicit aliases remain supported only for their established legacy shape.

Authoritative selected Extras are read only from an explicitly present `booking.extras` or `cancelledRequest.extras` array. An empty array is a valid complete snapshot; a missing or non-array field is not evidence that a reservation has zero Extras and is exported as a safe failure instead. The runtime exporter does not recursively discover or accept arbitrary `extras` containers.

Paste the helper into a Turo tab, then run:

```js
await FleetOSTuroExtrasExporter.run(["70000001", "70000002"])
```

The protected Extras Import page lists upcoming pickups and active/in-progress trips in the active company, including reservations previously verified with empty or nonempty Extras. Candidates are ordered by pickup time and capped at 500. An exact numeric reservation lookup can also select a historical company-owned trip. CSV aggregate Extras dollars do not affect eligibility. Copy the displayed IDs into the helper, download the sanitized file, and upload it to FleetOS. This is operator-assisted verification; FleetOS learns about subsequent Turo changes only after another export/import. Verify again during pickup preparation.

Never verified means **Extras not verified for this reservation**. A complete empty snapshot means **No Extras observed as of [timestamp]**, not a promise that no subsequent changes occurred. Complete nonempty observations display the purchased selections and verification time. Source observation timestamps are stored in UTC and displayed in the application timezone (Pacific/Honolulu). A later partial observation, failed export, rejected reservation, or equal-time conflict displays a refresh warning while retaining the last complete verification. Newly recorded Extras errors carry server-derived company ownership and source observation time; legacy errors without those facts are not assigned to a company by guesswork.

The accepted JSON root is:

```json
{
  "schema": "fleetos-turo-extras-v1",
  "exported_at": "2026-09-13T08:00:00-10:00",
  "reservations": [],
  "failures": []
}
```

Reservation and Extra objects use a strict whitelist. Unknown fields are rejected. The upload is limited to 2 MB and 500 reservations.

## Canonical identity and mappings

`fleet_extras` is the company-owned business catalog. Its code is unique within a company and becomes immutable after mapped activity exists. Display names, ordering, notes, and active status remain editable; inactive records and their history are retained.

Turo `extraId` is source identity, not durable business identity. Recreated or vehicle-specific Turo Extras can have different IDs even when the operator considers them the same product. `fleet_extra_source_mappings` therefore maps `(company, source system, source Extra ID)` to a canonical Extra. Labels, descriptions, types, and prices never auto-map an unseen ID. The same label may legitimately map to different canonical Extras, and many source IDs may map to one canonical Extra.

Selections deliberately do not copy `fleet_extra_id`. Canonical identity resolves dynamically through the mapping table, avoiding duplicated truth and making an audited remap take effect consistently. A remap to a different canonical Extra requires an operator reason and records old/new values in the existing audit log.

## Selection snapshots and history

`turo_extra_reservation_snapshots` preserves every accepted reservation snapshot and its sanitized source payload. `turo_extra_selections` uses `(company, reservation ID, reservationStateExtraId)` as stable identity and preserves source IDs, text, selected price, optional quantity, dates, status, observation timestamps, payload hash, and snapshot provenance.

Price is historical selection data; it is never taken from a current catalog value. Missing quantity stays `NULL`, and gross amount stays `NULL` unless quantity was explicitly supplied. An unmatched reservation remains company-scoped and does not acquire a fabricated trip link. A local trip link is made only through the existing authoritative Turo trip/reservation identity for a vehicle owned by the active company.

Re-importing an identical file is idempotent. A newer complete observation updates current source fields and `last_observed_at`; a new reservation-state Extra identity inserts a row. Missing selections are marked `removed_at` only from a valid snapshot explicitly marked complete. Partial observations are preserved as evidence only: they neither add/change current selections nor remove, reactivate, or reopen operational work. Only complete observations advance the authoritative lifecycle watermark.

Equal-time equivalent reservation observations are no-ops even when the file encoding or Extra array order differs. Equal timestamps with different sanitized payloads are rejected per reservation, reported as `extras_observation_conflict`, and preserved in the existing import-error evidence. They do not replace current selections or become accepted verification snapshots. Obtain a fresh export with a later timestamp to resolve ambiguity. MySQL imports serialize these checks using the company row inside the existing transaction. Deleted/recreated Turo Extras keep their old mappings; each new source ID enters the unmapped queue for an explicit decision.

Selections imported before their normalized trip exists retain their source evidence. The existing trip-import fulfillment reconciliation hook now attaches previously unmatched selections and snapshots by unambiguous company-owned reservation/trip identity. The protected **Match saved Extras to imported trips** POST action also reconciles already-created trips. No source-file replay or GET-side mutation is required; commercial fields and source payloads remain unchanged.

## Movement visibility and fulfillment

Movement Guest Commitments contains a read-only **Purchased Extras** summary and separate **Special instructions** marked Manual. Every active selection remains visible, including unmapped source products. Unknown products display their Turo label, optional quantity, and **Operational mapping required**, with no invented physical instruction or readiness blocker. Removed selections are absent from the active summary and retain existing fulfillment history.

Mapped selections use the existing canonical catalog's fulfillment type, phase, confirmation flag, blocking flag, and operator action. No label-based behavior is introduced. The summary creates no tasks or completion controls; the existing Trip Preparation fulfillment remains the sole purchased-Extra completion authority. Manual commitments remain independent and do not alter imported commercial truth. Automatic manual/imported reconciliation remains out of scope.

## Operations and security

All Extras browser routes require `session` and `admin.access`; POST requests use the global CSRF filter. Company ID and actor are server-derived. Catalog, mapping, trip matching, imports, and queue queries are company-scoped. Mapping, reservation, and existing-selection reads are batched; the unmapped queue is set-based.

Safe synthetic fixtures live in `tests/_support/fixtures`. They cover two Turo IDs with the label “Beach gear,” changed historical pricing, the “Portable GPS” alias, omitted quantity, repeat import, and complete-snapshot removal. They contain no production or guest data and are not migration seed data. The earnings real-shape fixture preserves export shape with explicitly invented guest names, reservation/vehicle identifiers, and payment suffixes.

Keep private exports and any downloaded receipt/detail material in `private/import-sources/`, which Git ignores. The narrow `fleetos-turo-extras-*.json` pattern also protects exporter downloads accidentally saved elsewhere in the repository. Do not commit source receipts/HTML, guest information, credentials, cookies, or session tokens. The sanitized JSON contract and operator's existing authenticated-tab mechanism are unchanged; no unattended automation or official API support is implied.

## Extras verification freshness

`ExtrasVerificationFreshnessPolicy` derives source age and pickup applicability from the latest complete reservation snapshot. It preserves historical evidence and reports `never`, `current_empty`, `current_nonempty`, `stale_empty`, or `stale_nonempty`, with a separate unresolved failed/incomplete attempt overlay. Only source `observed_at` establishes verification; importing an old export again or changing metadata cannot reset age. Future observations are untrusted and cannot satisfy preparation.

Typed `Config\ExtrasVerification` defaults centralize a 24-hour preparation window, 24-hour maximum complete-observation age, and 72-hour advisory horizon. This slice has no migration, company Settings dependency, or Settings UI. These operating windows do not establish a Turo purchase cutoff.

Inside preparation, a complete observation qualifies at or after `max(pickup_at - preparationWindowHours, as_of - maxCompleteAgeHours)`. Exactly 24 hours old is current; preparation opens exactly at its boundary. A recent snapshot captured before preparation remains current by age but requires refresh. A failed/incomplete attempt at or after the last complete observation, including an equal-time conflict, requires a new complete observation during preparation.

Beyond 72 hours, age remains informational. Within the advisory horizon, missing/stale/problematic verification creates a nonblocking refresh action. Inside preparation, it blocks pickup readiness. An overdue pickup without its own actual handoff remains unresolved. An owned, unvoided handoff closes pickup preparation; another trip's handoff does not. Completed/canceled/deleted/invalid trips acquire no new pickup requirement. Active trips may still be refreshed voluntarily without reopening historical pickup readiness.

All surfaces consume the same company/trip source-verification model and clock. Stored observations are parsed as UTC, normalized trip schedules as application-local time, and comparisons use epoch seconds. Operator timestamps display Pacific/Honolulu. Readiness, board fallback, and queue work share a stable company/trip identity. Return projections retain next-trip ownership and exclude this future requirement from current return readiness.

`Refresh Turo Extras` navigates read-only to `/turo/extras?reservation_id=<owned-id>#export-heading`. It does not import on click. Guest custody does not suppress browser/source work. The existing operator-assisted sanitized exporter remains unchanged. Purchased Extra fulfillment stays independent: no fake selections, fulfillment records, manual verification checkbox, or fabricated historical snapshots are created.

The workspace evaluates eligible candidates before sorting required refreshes ahead of advisory and informational candidates, then applies the existing 500-reservation export limit. The board evaluates eligible work without this export cap. The board also batches eligible reservation evidence, so overdue pickups and advisory work do not depend on having a persisted checklist or being the selected next trip. Queue actions are deduplicated across current/next-trip projections and use exact-reservation destinations.

## Financial firewall and next slice

Extra amounts are commercial attribution facts called selected price, Extra sale amount, or gross Extra sales. They are not host earnings and are not financial postings. Slice A does not touch the financial activity readers or the formulas for realized revenue, recoveries, operating costs, or net realized operating result.

Extras Performance Slice B can build reporting and filters from this foundation after manual acceptance. Loan ledgers, trip profitability, performance ranking, and CSV reporting remain out of scope here.
