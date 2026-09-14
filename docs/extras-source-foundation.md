# Extras source, mapping, and activity foundation

Slice A establishes source-backed Extra activity without adding a performance report or changing FleetOS financial totals.

## Source and privacy contract

The operator runs `tools/turo-extras-exporter.js` in browser developer tools while already authenticated on `https://turo.com`. The helper calls Turo's same-origin reservation-detail endpoint sequentially and downloads a local `fleetos-turo-extras-v1` JSON file. It never reads or emits cookies, authorization headers, session tokens, guest contact data, licenses, messages, or unrelated reservation data. FleetOS does not log into Turo and has no scraping proxy.

Paste the helper into a Turo tab, then run:

```js
await FleetOSTuroExtrasExporter.run(["70000001", "70000002"])
```

The protected Extras Import page exposes only active-company reservation IDs that do not yet have a complete snapshot. Copy those IDs into the helper, download the sanitized file, and upload it to FleetOS. An export failure contains only the reservation ID and a safe error description.

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

Re-importing an identical file is idempotent. A present selection updates allowed current source fields and `last_observed_at`; a new reservation-state Extra identity inserts a row. Missing selections are marked `removed_at` only from a valid snapshot explicitly marked complete. Partial or invalid reservation blocks cannot remove history. Deleted/recreated Turo Extras keep their old mappings; each new source ID enters the unmapped queue for an explicit decision.

## Operations and security

All Extras browser routes require `session` and `admin.access`; POST requests use the global CSRF filter. Company ID and actor are server-derived. Catalog, mapping, trip matching, imports, and queue queries are company-scoped. Mapping, reservation, and existing-selection reads are batched; the unmapped queue is set-based.

Safe synthetic fixtures live in `tests/_support/fixtures`. They cover two Turo IDs with the label “Beach gear,” changed historical pricing, the “Portable GPS” alias, omitted quantity, repeat import, and complete-snapshot removal. They contain no production or guest data and are not migration seed data.

## Financial firewall and next slice

Extra amounts are commercial attribution facts called selected price, Extra sale amount, or gross Extra sales. They are not host earnings and are not financial postings. Slice A does not touch the financial activity readers or the formulas for realized revenue, recoveries, operating costs, or net realized operating result.

Extras Performance Slice B can build reporting and filters from this foundation after manual acceptance. Loan ledgers, trip profitability, performance ranking, and CSV reporting remain out of scope here.
