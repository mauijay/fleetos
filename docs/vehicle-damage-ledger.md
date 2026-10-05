# Vehicle damage ledger — Phase B1

Phase B1 adds incidents and explicit condition relationships to the Phase A physical-condition ledger. Implementation and verification are local only. No production migration or historical linking has been performed.

## Baseline and delivery

- Branch: `feature/damage-ledger-phase-b1`.
- Exact base: `d79ef7834c40164136a4188b0e34ea190cc841ec` (accepted v0.26.0 baseline).
- Delivery is a local feature commit after the final review gates. No push, deployment, tag, version change, or CHANGELOG change is included.
- No production access; all records used in verification are synthetic. Existing private import artifacts were not read or changed.

## Files changed

| Files | Purpose |
| --- | --- |
| `app/Database/Migrations/2026-10-05-000029_AddVehicleDamageIncidentsAndConditionLinks.php` | One additive B1 migration |
| `app/Database/Migrations/2026-06-25-000005_AddOperationalTripForeignKeys.php`, `app/Database/Migrations/2026-09-26-000028_CreateSuperchargerReconciliation.php`, `app/Database/SQLiteMigrationTable.php` | Authorized minimum SQLite compatibility fixes for the complete fresh chain; retain exact foreign-key targets/actions and incoming references during rebuilds; MariaDB behavior unchanged |
| `app/Config/VehicleDamage.php` | Panel, effect, attribution catalogs and compatible zones |
| `app/Repositories/VehicleDamageIncidentRepository.php` | Incident, membership, normalized-trip selectors and authorized audit reads |
| `app/Services/Fleet/VehicleDamageIncidentService.php` | Transactional incidents, membership effects, attribution and historical linking |
| `app/Services/Fleet/VehicleDamageReadService.php` | Shared vehicle/checklist read entry point |
| `app/Controllers/VehicleDamageIncidents.php` | Company-authorized B1 forms/details/actions |
| `app/Views/vehicle_damage_incidents/{page,new,_form,_areas,show,item,link_preview}.php` | Incident entry/detail, original-item provenance/evidence and explicit preview |
| `app/Repositories/VehicleDamageRepository.php` | Canonical projection/resolver, locks, trip/evidence hardening |
| `app/Services/Fleet/VehicleDamageService.php` | Canonical mutation reuse, observations/evidence, locked stale-state checks and audit context |
| `app/Config/{Routes,Services}.php` | Protected routes and dependency wiring |
| `app/Controllers/{VehicleCapital,TripMovementChecklists}.php` | Shared read projection; vehicle incident history |
| `app/Views/fleet_vehicles/components/vehicle_damage.php`, `app/Views/trip_movement_checklists/_known_damage.php` | Precise panel display, incident entry/history and provenance links |
| `resources/css/app.css` | Bounded mobile area controls |
| `tests/database/{VehicleDamageServiceTest,VehicleDamageIncidentServiceTest}.php` | Phase A fixture fidelity and B1 integration coverage |
| `tests/database/VehicleDamageMigrationTest.php` | Full SQLite fresh migration chain, schema/FKs/index, seed, rerun and rollback-history safety |
| `tests/unit/{VehicleDamageReadServiceTest,VehicleDamageIncidentViewTest}.php` | Resolver, projection consumers, markup/privacy/preview/route coverage |
| `docs/vehicle-damage-ledger.md` | Implementation, validation and acceptance report |

31 changed/new reviewable files in total. The original 27 B1 paths plus two earlier migrations, a SQLite rebuild helper, and its fresh-chain test. Generated frontend output, browser screenshots, isolated PHP configuration, and disposable MariaDB verification artifacts stay under ignored build directories.

## Additive migration

`2026-10-05-000029_AddVehicleDamageIncidentsAndConditionLinks` is the only B1 migration.

It adds `vehicle_damage_incidents`, `vehicle_damage_incident_items`, nullable `panel_code`, and nullable `current_condition_item_id` on `vehicle_damage_items`. Incidents retain company/vehicle ownership, normalized trip, movement/recovery context, optional occurrence time, discovery time, attribution meaning, note, and actor/timestamps. Memberships retain the affected item, effect, panel/type/severity snapshot, note, and actor/timestamp. A unique incident/item pair prevents repeated membership in one incident; different incidents can affect the same condition.

Physical conditions remain authoritative. Migration does not create incidents for old rows, infer panels, deduplicate, rewrite descriptions, resolve items, or alter events/evidence. All preexisting rows retain null new columns. Membership panel is also nullable to preserve provenance for historical items whose precise panel was never recorded.

Both engines use foreign keys with deletion restricted. SQLite uses native additive columns to avoid framework table rebuilds rewriting existing event/evidence foreign keys. MariaDB uses Forge additive columns/indexes/foreign keys. Destructive `down()` is deliberately refused: reverting code must not discard incident history; use an approved restoration plan.

Deployment implication: take an approved backup and apply the normal forward migration runner once. Its migration-history entry prevents a successful rerun from applying B1 again. Calling `up()` directly is not idempotent. Refused rollback leaves the B1 schema/history intact, and normal forward rerun remains usable. MariaDB DDL is not an atomic multi-statement transaction: after an interrupted migration, inspect the schema/history and restore or follow an approved recovery plan before retrying. Do not blindly rerun partial DDL or delete history to force a rollback.

## Panel and attribution semantics

The explicit panel catalog contains front bumper, hood, both front fenders, all four doors, both rear quarter panels, rear bumper, trunk/liftgate, windshield, roof/glass, all four wheel/tire areas, interior, underbody, and other. New conditions receive both a precise panel and a compatible broad zone. Same-panel conditions stay independent until an operator explicitly links them. Legacy null panels render their original zone.

Existing damage types remain available, with `puncture_cut` and `mechanical_underbody` added. Severity codes/ranking remain unchanged; `unsafe` displays as “Safety-critical.”

Attribution types are `unknown`, `discovered_during_trip`, `suspected_cause`, and `operator_attributed_cause`. Discovery during a trip does not establish causation. Non-unknown attribution requires an owned active normalized trip; reservation display derives from that trip, never posted reservation/guest text. No guest name is stored in damage tables. Observations cannot be assigned causal attribution, including through later attribution correction.

## Incident behavior

| Effect | Condition mutation | History |
| --- | --- | --- |
| `new_damage` | Create one open physical condition per area, precise panel and compatible zone | Initial `created` event; incident membership snapshot; global audits |
| `worsened` | Affect one existing current canonical condition; severity may remain equal or increase | `worsened` event with prior/new condition state and source context; immutable membership snapshot |
| `observed_existing` | Preserve severity, identity, discovery, and physical status | Observation event/note and membership; no implied causation |

One incident with four new areas produces one incident, four conditions, and four memberships. A later incident can worsen a current owned condition or observe current/resolved history. Historical aliases resolve to their canonical condition for membership and mutation; evidence stays attached to the selected original item. Ownership, panel where already known, severity, actor, timestamps, effect, and note are validated on the server. Invalid areas/evidence roll back the complete incident, including conditions, events, memberships, evidence, and audit records.

## Historical relationships and canonical projection

“Link as worsened existing damage” first displays a preview of source and target: panel/zone, description, trip/discovery context, severity/status, evidence count, remaining canonical ID, and current count effect. Submission requires an explicit checkbox, reason, and fingerprints of both records.

The transaction locks the vehicle and affected items in ID order. It rejects missing/wrong-company/wrong-vehicle records, self-links, existing outgoing relationships, a source with incoming historical relationships, stale previews, and resolved targets. This prohibits cycles and chains. Target severity becomes the higher source/target severity, preserving safety-critical conditions.

The source retains its ID, original discovery/context, evidence, all existing events, status, creation metadata, description, and severity. Only its canonical pointer and normal update metadata change. A source relationship event and global audit capture the reason; target worsening appends its own event/audit. An explicit provenance incident preserves source membership plus a worsened target membership. No source is deleted or marked repaired to hide a duplicate.

Known next-step limitation: B1 has no audited unlink/retarget action or relationship-correction UI. The nullable relationship and preserved source/target records, events, memberships and before/after audit allow a future corrective transaction without losing provenance. Operators must not correct a relationship through direct SQL. A correction needs locking, an explicit reason, old/new target audits, and a decision about any severity/status changes already recorded on the old target.

`VehicleDamageRepository::canonicalItem()` is the shared one-hop resolver. A null pointer resolves to itself; a non-null pointer must reference an owned canonical item. Missing targets, self-references, and chains fail closed rather than being followed recursively. Detail/severity/status/worsening/observation mutations use this resolver consistently. Evidence remains on its original authorized parent to preserve provenance.

The repository current projection selects open/accepted-unrepaired canonical items only. `VehicleDamageReadService` supplies the same workspace to the vehicle controller and Movement Checklist. Explicitly linked historical records do not contribute duplicate current cards or counts. Vehicle history exposes those records separately; canonical detail links to their original events and evidence. There is no panel/text/type deduplication.

## Post-create linkage and evidence

Operators can add/correct an incident's normalized trip with a required reason and stale-state fingerprint, attach another area/membership with an explicit effect, link existing historical conditions, and attach evidence to any owned original item. Original discovery/creation fields remain unchanged. A trip correction cannot silently move existing movement/recovery context to a different trip.

Evidence reuses Phase A item references. Each area may supply one existing vehicle file, image, or external text reference; later attachment accepts the same choices. File/image attachment and reads validate company, vehicle association, parent item, live metadata, local storage, and safe relative private paths. Soft-deleted, reassigned, public, remote, absolute, and traversal metadata are rejected/excluded. External references are escaped text; stored paths and public evidence URLs are never rendered. No new uploads/download endpoints, parallel storage system, or nullable event reference were needed.

Every creation/effect/link/correction/evidence action writes a global audit with actor and before/after state; mutation reasons and evidence linkage are included. Damage events are append-only where the action affects condition history. Incident detail exposes incident audit history plus dated membership snapshots; item detail exposes original evidence/events.

## Mutation protection

All condition writes and B1 incident writes serialize on the owned vehicle row. Affected condition rows also lock for updates. Historical linking validates complete preview state after locking; condition updates compare the locked record with the validated snapshot before changing it. Trip attribution compares its incident fingerprint. Concurrent state is rejected rather than overwritten.

MariaDB uses `FOR UPDATE`. SQLite uses transaction/database write locking and the same explicit state checks. MariaDB verification used two independent connections: one held vehicle/item locks while the other attempted a historical link. The contender failed without changing the relationship; linking succeeded after the first connection released its transaction.

Selectors and mutations are company scoped. Normalized trip lookup additionally checks trip company, exact vehicle, and `deleted_at`. Evidence reads check live metadata and current vehicle association. Existing claims remain separate and their Phase A regressions continue to pass; no unrelated claims refactor was added.

## UX and boundaries

The vehicle condition section has a primary “Record damage incident” entry and an incident history list. The Movement Checklist links to the same multi-area entry and consumes the same current condition projection. The form provides vehicle/discovery/optional occurrence/optional normalized reservation/attribution/note, repeatable removable area rows, existing-condition selection, and optional evidence per area. Failed creation and later actions preserve operator input. Responsive grids, bounded controls, wrapping text, and existing mobile action styles are reused. The incident pages load the shared POST submission guard. Invalid historical previews do not present confirmation or a misleading count result.

After saving, the incident detail shows area snapshots and offers later trip attribution and membership attachment. Condition detail provides original evidence/events and historical relationship preview. Phase A entry/update actions remain available for compatibility.

No repair jobs, mitigation, estimates, repair costs, claims redesign, payments, recovery money, net host cost, operating expenses, financial summary changes, or future financial placeholder columns are included.

## Local verification

- Focused damage suite: 49 tests, 522 assertions covering Phase A regressions, B1 service/ownership/soft-delete/rollback/audits, fresh migration chain and upgrade preservation, one-hop resolution including malformed zero pointers, historical membership, resolved observation, vehicle/checklist card parity, escaped retained input, invalid/valid confirmation preview, and session/admin/CSRF route configuration.
- Full PHPUnit: 986 tests, 7,081 assertions, no failures/errors; 3 deprecations and 79 notices from the wider suite. Focused damage tests are clean.
- PHPStan: no errors.
- All changed PHP files: syntax passes.
- Changed-file CS Fixer: project configuration, views excluded according to its Finder; final dry-run clean.
- `git diff --check`: clean. Frontend Vite build and all 36 existing JavaScript tests pass.
- MariaDB 10.4.32: disposable local instance under ignored `build/b1-review/mariadb`, localhost port 33180, datadir verified before any write. The complete 35-migration App chain produced 104 tables; seed, forward rerun and refused rollback/history preservation passed. Upgrade preserved legacy rows/events/evidence. Four-area creation, canonical linking, 23 ledger/fixture foreign keys, two-connection link/worsening contention, stale severity rejection, and chain/cycle rejection passed.
- SQLite uses the configured in-memory `tests` connection with `db_` prefix. The complete 35-migration chain, all referenced-table existence checks, B1 FKs/index, lookup seed, rerun, refused rollback/history preservation, representative Phase A upgrade and `PRAGMA foreign_key_check` pass.
- Local Chrome acceptance uses actual controllers/services/views with a synthetic operator and isolated persistent SQLite fixtures. All eight requested scenarios passed at 390px and 1280px; preview/vehicle/checklist/history/detail also passed at 1920px. Twenty-nine captured page checks show no horizontal overflow, overlapping action buttons, or unlabelled controls. Four area rows and remove/add indexing retain adjacent values. Vehicle/checklist cards both show four current canonical conditions after the explicit fender relationship. Screenshots were visually reviewed; no private/live images were used.
- Additional live browser checks pass for keyboard traversal through labelled controls, server-side validation with retained notes, missing-CSRF refusal, evidence attached to a historical source with its parent preserved, later trip attribution with unchanged discovery, and a historical-alias membership targeting the canonical condition. Current count remains four after these later actions. Static assets use an ignored localhost proxy to avoid the single-thread Windows PHP development server blocking on browser preconnections.
- PHP startup was isolated using ignored `build/php-b1.ini`, disabling the machine's unavailable IMAP extension for test child processes. No machine-level configuration changed.
- Synthetic fixtures only; no private evidence files, customer identifiers, import artifacts, credentials, or local database artifacts are included in tracked changes.

## Proposed release preparation and production acceptance

1. Recommend v0.27.0 for the additive incident feature, subject to release preparation. Review the feature commit and additive migration; approve the release process separately before any production action. No version change is part of B1.
2. On a disposable staging copy, back up and record item/event/evidence counts and IDs; apply B1 and verify all old rows remain unchanged with null new fields.
3. Create one four-panel synthetic incident; verify one incident, four current cards, precise panels, correct reservation context, and vehicle/checklist parity on desktop/mobile.
4. Record a later worsening and observation. Confirm stable canonical identity, immutable snapshots, no severity downgrade, no observation causation, and clear safety-critical display.
5. Preview a known historical duplicate using approved operator evidence. Confirm both contexts/evidence counts, choose the canonical item, supply a reason, and verify one current card with the source's original history/evidence still accessible.
6. Try unrelated same-panel conditions, cross-company/vehicle selections, deleted trips/evidence, self/cycle/chain links, and stale previews; confirm refusal without partial writes.
7. Add attribution, a membership, and evidence after creation. Verify unchanged discovery fields and actor/reason/before-after history.
8. Use two operators to race a link against severity/status changes; confirm serialization or a clear stale-state refusal. No bulk historical auto-linking is authorized.

Scope deviations: historical-provenance membership panel is nullable for legacy records; no event-specific evidence reference or extra upload system was necessary. Extra view templates provide item provenance and explicit historical preview. The user authorized the minimum earlier-migration SQLite compatibility fixes required by the fresh-install gate. Browser acceptance uses local synthetic fixtures; production acceptance remains a separate release step.
