# Trip Movement Checklists

FleetOS checklists are focused operational records tied to real trip movements. They are not a generic task system.

## Uniqueness

One checklist is created per movement using `turo_trip_normalized_id`, `movement_type`, and `scheduled_at`. Repeated dashboard loads call the checklist service idempotently and do not create duplicates.

## Pickup Checklist

Default pickup items are vehicle inspected, vehicle cleaned, charge confirmed, exterior photos completed, interior photos completed, key card confirmed, pickup or delivery location confirmed, vehicle staged or ready, and guest handoff completed.

Airport pickups also add airport staging, parking location, and guest pickup instructions when the movement is associated with airport delivery data.

Airport pickups also include `Turo Access instructions confirmed`. FleetOS completes this item from the airport workflow only when verified guest instructions containing the no-ticket warning are marked sent.

## Return Checklist

Default return items are return received or vehicle located, return time confirmed, exterior inspected, interior inspected, damage check completed, charge confirmed, return photos completed, cleaning status assigned, vehicle disposition assigned, and return workflow completed.

Allowed return dispositions are `available`, `needs_cleaning`, `needs_charging`, `maintenance_required`, `claim_review_required`, and `offline`.

## Readiness Rules

Pickup readiness requires all critical applicable pickup items to be complete. Return readiness requires all critical applicable return items to be complete and a vehicle disposition selected. Optional open items do not block readiness. Not-applicable items are excluded from completion percentage.

## Completion Sources

Current checklist completion is manual. FleetOS stores `completion_source = manual`, a completion timestamp, and an optional note. Physical actions such as cleanliness, charge, damage-free condition, and key-card presence are not inferred automatically.

## Command Center Integration

Command Center creates and summarizes current-day checklists through `TripMovementChecklistService`. The Movement Board shows checklist readiness and remaining required items. Today's Timeline links each pickup or return to the correct checklist.

## Performance

Command Center loads current-day checklist summaries only. Detailed checklist items are loaded only when the operator opens a specific checklist.
