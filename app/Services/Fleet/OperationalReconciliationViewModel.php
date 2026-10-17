<?php

namespace App\Services\Fleet;

use App\DTOs\Fleet\OperationalReconciliation;

/** Presentation only: all findings, scopes and classifications come from Slice 1. */
final class OperationalReconciliationViewModel
{
    public const CATEGORIES = ['movement' => 'Movement', 'position' => 'Position', 'checklist' => 'Checklist', 'import' => 'Import', 'readiness' => 'Readiness', 'extras' => 'Extras / Guest Commitments'];
    public const LEGEND = [
        'P0' => 'Current custody or position authority needs immediate review.',
        'P1' => 'Genuine preparation work before an upcoming trip.',
        'P2' => 'Historical, stale or data-quality review without imminent impact.',
        'P3' => 'Advisory, scheduled future work or nonurgent review.',
    ];
    private const FAMILIES = ['cleaning' => 'Cleaning', 'energy_fuel' => 'Energy / Fuel', 'photos' => 'Photos', 'keys_adapter' => 'Keys / Adapter', 'pickup_location' => 'Pickup location', 'staging_parking' => 'Staging / Parking', 'extras' => 'Extras', 'guest_commitments' => 'Guest Commitments', 'other' => 'Other'];
    public const PAGE_SIZE = 25;

    public function summary(OperationalReconciliation $projection): array
    {
        $counts = array_fill_keys(ReconciliationIssue::SEVERITIES, 0);
        $with = 0;
        foreach ($projection->vehicles as $vehicle) {
            $with += $vehicle['issues'] !== [] ? 1 : 0;
            foreach ($vehicle['issues'] as $issue) {
                $counts[$issue['severity']]++;
            }
        }
        return ['counts' => $counts, 'reviewed' => count($projection->vehicles), 'with_findings' => $with,
            'without_findings' => count($projection->vehicles) - $with, 'priority' => $counts['P0'] > 0 ? 'P0' : ($counts['P1'] > 0 ? 'P1' : 'quiet')];
    }

    public function present(OperationalReconciliation $projection, array $query = []): array
    {
        $context = new ReconciliationContextService($projection);
        $target = $context->target($query);
        $errors = $target['errors'];
        $filters = $target['filters'];
        foreach (['severity' => ReconciliationIssue::SEVERITIES, 'scope' => ReconciliationIssue::SCOPES, 'category' => array_keys(self::CATEGORIES)] as $key => $allowed) {
            $value = $query[$key] ?? '';
            if (! is_string($value) || ($value !== '' && ! in_array($value, $allowed, true))) {
                $errors[] = 'Choose a valid ' . $key . ' filter.';
                $value = '';
            }
            $filters[$key] = $value;
        }
        $vehicles = [];
        foreach ($projection->vehicles as $id => $vehicle) {
            $vehicles[$id] = $this->identity($vehicle);
        }
        asort($vehicles, SORT_NATURAL | SORT_FLAG_CASE);
        $id = $query['vehicle'] ?? '';
        if (! is_string($id) || ($id !== '' && (! ctype_digit($id) || ! isset($vehicles[(int) $id])))) {
            $errors[] = 'Choose an available vehicle.';
            $id = '';
        }
        $id = $id === '' ? '' : (string) (int) $id;
        $filters['vehicle'] = $id;
        $search = $query['q'] ?? '';
        if (! is_string($search) || mb_strlen($search) > 100) {
            $errors[] = 'Search must contain at most 100 characters.';
            $search = '';
        }
        $filters['q'] = trim($search);
        $page = $query['page'] ?? '1';
        if (! is_string($page) || ! ctype_digit($page) || (int) $page < 1 || strlen($page) > 6) {
            $errors[] = 'Choose a valid page.';
            $page = '1';
        }
        if (array_diff(array_keys($query), ['severity', 'scope', 'category', 'vehicle', 'q', 'page', 'trip', 'checklist', 'issue', 'return']) !== []) {
            $errors[] = 'Unsupported filter. Use the available review filters.';
        }
        $rows = [];
        $matchingByVehicle = [];
        foreach ($projection->vehicles as $vehicleId => $vehicle) {
            foreach ($vehicle['issues'] as $issue) {
                if (($filters['severity'] !== '' && $issue['severity'] !== $filters['severity'])
                    || ($filters['scope'] !== '' && $issue['scope'] !== $filters['scope'])
                    || ($filters['category'] !== '' && explode('.', $issue['code'])[0] !== $filters['category'])
                    || ($id !== '' && (int) $id !== (int) $vehicleId)
                    || ! $context->matches($issue, $target)) {
                    continue;
                }
                $haystack = $vehicles[$vehicleId] . ' ' . $issue['summary'] . ' ' . implode(' ', array_map(static fn (int $trip): string => 'Trip ' . $trip, $issue['trip_ids']));
                if ($filters['q'] !== '' && mb_stripos($haystack, $filters['q']) === false) {
                    continue;
                }
                $rows[] = ['vehicle_id' => $vehicleId, 'issue' => $issue];
                $matchingByVehicle[$vehicleId] = ($matchingByVehicle[$vehicleId] ?? 0) + 1;
            }
        }
        usort($rows, static function (array $a, array $b) use ($projection, $vehicles): int {
            $key = static fn (array $row): array => [$row['issue']['severity'], $projection->vehicles[$row['vehicle_id']]['next_trip_urgency']['pickup_at'] ?? '9999', mb_strtolower($vehicles[$row['vehicle_id']]), $row['issue']['code'], $row['issue']['fingerprint']];
            return $key($a) <=> $key($b);
        });
        $total = count($rows);
        $pages = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = min((int) $page, $pages);
        $groups = [];
        foreach ($errors === [] ? array_slice($rows, ($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE) : [] as $row) {
            $vehicle = $projection->vehicles[$row['vehicle_id']];
            $key = $row['issue']['severity'] . ':' . $row['vehicle_id'];
            if (! isset($groups[$key])) {
                $groups[$key] = ['id' => $row['vehicle_id'], 'name' => $vehicles[$row['vehicle_id']], 'highest_severity' => $vehicle['highest_severity'],
                    'total' => count($vehicle['issues']), 'matching' => $matchingByVehicle[$row['vehicle_id']],
                    'custody' => $this->human($vehicle['custody']['custody']), 'position' => $this->position($vehicle),
                    'current_trip' => $this->trip($vehicle['import_current_trip']), 'movement_trip' => $this->trip($vehicle['movement_current_trip']),
                    'next_trip' => $this->trip($vehicle['next_confirmed_trip']), 'issues' => []];
            }
            $groups[$key]['issues'][] = $this->issue($row['issue'], $vehicle);
        }
        return ['summary' => $this->summary($projection), 'as_of' => $projection->asOf, 'filters' => $filters, 'errors' => $errors,
            'return_link' => $errors === [] ? $target['return_link'] : null, 'context_label' => $errors === [] ? $target['label'] : null,
            'vehicles' => $vehicles, 'categories' => self::CATEGORIES, 'legend' => self::LEGEND, 'groups' => array_values($groups),
            'total' => $total, 'page' => $page, 'pages' => $pages, 'previous' => $page > 1 ? $this->url($filters + ['page' => $page - 1]) : null,
            'next' => $page < $pages ? $this->url($filters + ['page' => $page + 1]) : null];
    }

    private function issue(array $issue, array $vehicle): array
    {
        $e = $issue['evidence'];
        $category = explode('.', $issue['code'])[0];
        $facts = [];
        $add = static function (string $label, string $value) use (&$facts): void {
            $facts[] = ['label' => $label, 'value' => $value];
        };
        $add('Movement authority · recorded custody', $this->human($vehicle['custody']['custody']) . ' · ' . $this->event($vehicle['custody_basis_event']));
        $add('Import context · current reservation', $this->trip($vehicle['import_current_trip']));
        $add('Recorded current position', $this->position($vehicle));
        $add('Position basis', $this->event($vehicle['position_basis_event']));
        if (in_array($category, ['movement', 'import'], true)) {
            $add('Previous relevant reservation', $this->trip($vehicle['previous_relevant_trip']));
            $add('Affected schedule', $this->trip($e['trip'] ?? $e['schedule'] ?? $e['current_trip'] ?? null));
            $events = $e['events'] ?? [];
            if ($events !== []) {
                $add('First relevant movement', $this->event($events[0]));
                $add('Latest relevant movement', $this->event($events[count($events) - 1]));
            }
            if ($category === 'import') {
                $add('Review context', $issue['severity'] === 'P2' ? 'Source/status review; the engine reports no imminent readiness or current possession impact for this finding.' : 'Compare imported status with the recorded movement authority.');
            }
        }
        foreach (array_slice($e['later_events'] ?? $e['position']['later_events'] ?? [], -3) as $event) {
            $add('Later movement evidence', $this->event($event));
        }
        foreach (array_slice($e['intervals'] ?? [], 0, 4) as $interval) {
            $add('Guest interval · Trip ' . $interval['trip_id'], $interval['start'] . ' · ' . ($interval['end'] ?? 'No recorded recovery') . ' · handoff event #' . $interval['handoff_id']);
        }
        $generations = $e['generations'] ?? (isset($e['generation']) ? [$e['generation']] : []);
        if ($category === 'checklist') {
            foreach ($vehicle['checklists'] as $generation) {
                if (in_array($generation['trip_id'], $issue['trip_ids'], true) && ! in_array($generation['id'], array_column($generations, 'id'), true)) {
                    $generations[] = $generation;
                }
            }
        }
        $links = [['label' => 'Vehicle details', 'href' => '/fleet/vehicles/' . (int) $issue['vehicle_id']], ['label' => 'Movement / trip history', 'href' => '/operations/vehicles/' . (int) $issue['vehicle_id'] . '/trip-history']];
        foreach (array_slice($generations, 0, 10) as $g) {
            $add(($g['is_current'] ? 'Canonical' : 'Obsolete') . ' checklist #' . $g['id'], 'Trip ' . $g['trip_id'] . ' · ' . $this->human($g['direction']) . ' · snapshot ' . ($g['scheduled_at_snapshot'] ?? 'Not recorded') . ' · authoritative schedule ' . ($g['authoritative_scheduled_at'] ?? 'Not recorded') . ' · persisted state: ' . ($g['persisted_workflow_state'] ?? 'Not recorded') . ' · basis: ' . ($g['is_current'] ? 'Matches canonical schedule' : $this->human($g['obsolete_reason'] ?? 'Schedule mismatch')));
            $links[] = ['label' => 'Review checklist #' . $g['id'], 'href' => '/operations/checklists/' . (int) $g['id']];
        }
        if (count($generations) > 10) {
            $add('Additional checklist generations', (count($generations) - 10) . ' more in movement / trip history.');
        }
        $items = $e['items'] ?? [];
        if ($category === 'extras') {
            $items = array_values(array_filter($vehicle['readiness']['items'], static fn (array $item): bool => in_array($item['trip_id'], $issue['trip_ids'], true) && in_array($item['family'], ['extras', 'guest_commitments'], true) && $item['status'] === 'unsatisfied'));
        }
        $families = [];
        foreach (array_slice($items, 0, 30) as $item) {
            $family = self::FAMILIES[$item['family']] ?? 'Other';
            $families[$family][] = ['code' => $this->human($item['code']), 'classification' => $item['classification'], 'status' => $item['status'],
                'actionability' => $item['classification'] === 'HISTORICAL_DERIVED' ? 'Historical review' : ($item['actionable'] ? ($item['blocking'] ? 'Current blocker' : 'Actionable work') : 'Non-actionable / source review')];
        }
        if ($category === 'extras') {
            foreach (array_slice($e['rows'] ?? [], 0, 6) as $row) {
                $parts = [];
                foreach (['selection_id' => 'Purchased selection', 'fulfillment_id' => 'Fulfillment', 'commitment_id' => 'Guest Commitment', 'snapshot_id' => 'Source snapshot', 'state' => 'Source state', 'observed_at' => 'Source observed at'] as $key => $label) {
                    if (isset($row[$key])) {
                        $parts[] = $label . ': ' . $row[$key];
                    }
                }
                $add($issue['scope'] === 'HISTORICAL' ? 'Historical review · non-actionable' : 'Commitment source evidence', implode(' · ', $parts) ?: 'Existing unified commitment projection');
            }
        }
        if ($issue['severity'] === 'P1') {
            $add('Next confirmed pickup', $vehicle['next_trip_urgency']['pickup_at'] ?? 'Not supplied');
            $add('Staging / pickup requirements', implode(', ', array_map($this->human(...), $vehicle['next_trip_urgency']['required_movement_codes'])) ?: 'No additional requirement supplied');
        }
        $states = array_values(array_filter($vehicle['readiness']['projections'], static fn (array $state): bool => in_array($state['trip_id'], $issue['trip_ids'], true)));
        foreach (array_slice($states, 0, 10) as $state) {
            if (in_array($state['trip_id'], $issue['trip_ids'], true) && in_array($category, ['readiness', 'checklist'], true)) {
                $add('Derived readiness · Trip ' . $state['trip_id'], ($state['ready'] === null ? 'Physical readiness not projected' : ($state['ready'] ? 'Ready' : 'Not ready')) . ' · coverage ' . $state['coverage'] . ' · persisted checklist state: ' . ($state['persisted_workflow_state'] ?? 'Not recorded'));
            }
        }
        if (count($items) > 30) {
            $add('Additional readiness evidence', (count($items) - 30) . ' more requirements in the authoritative movement checklist.');
        }
        foreach ($vehicle['checklists'] as $generation) {
            if (count($links) >= 14) {
                break;
            }
            if ($generation['is_current'] && in_array($generation['trip_id'], $issue['trip_ids'], true) && ! in_array($generation['id'], array_column($generations, 'id'), true)) {
                $links[] = ['label' => 'Review ' . $generation['direction'] . ' checklist #' . $generation['id'], 'href' => '/operations/checklists/' . (int) $generation['id']];
            }
        }
        foreach (array_slice($issue['trip_ids'], 0, 10) as $tripId) {
            if (in_array($category, ['extras', 'readiness'], true)) {
                $links[] = ['label' => 'Trip ' . $tripId . ' · Extras / Guest Commitments', 'href' => '/operations/trips/' . (int) $tripId . '/commitments'];
            }
        }
        if ($category === 'import') {
            $links[] = ['label' => 'Import Issues', 'href' => '/turo/import-issues'];
        }
        $chronology = [
            ['label' => 'Recorded movement basis', 'value' => $this->event($vehicle['custody_basis_event'])],
            ['label' => 'Reconciliation finding', 'value' => $issue['summary']],
            ['label' => 'Next confirmed trip', 'value' => $this->trip($vehicle['next_confirmed_trip'])],
        ];
        foreach (array_slice(array_filter($vehicle['guest_commitments'], static fn (array $row): bool => in_array($row['trip_id'], $issue['trip_ids'], true)), 0, 10) as $source) {
            $add('Source freshness / Trip ' . $source['trip_id'], $this->human($source['verification_state']) . ' / refresh required: ' . ($source['refresh_required'] ? 'Yes' : 'No') . ' / snapshot ' . ($source['snapshot_id'] ?? 'Not supplied'));
        }
        return $issue + ['chronology' => $chronology, 'facts' => $facts, 'families' => $families, 'links' => $links, 'authority_label' => $this->human($issue['authority_basis']), 'trip_label' => implode(', ', array_map(static fn (int $id): string => 'Trip ' . $id, array_slice($issue['trip_ids'], 0, 10))) . (count($issue['trip_ids']) > 10 ? ' (additional trips in movement history)' : '') ?: 'Vehicle-level finding'];
    }

    private function identity(array $vehicle): string
    {
        return $vehicle['vehicle']['display_name'] ?: ($vehicle['vehicle']['fleet_code'] ?: 'Vehicle ' . $vehicle['vehicle']['id']);
    }

    private function position(array $vehicle): string
    {
        $p = $vehicle['position'];
        $location = $this->human($p['location_class']);
        if (($p['location_detail'] ?? '') !== '') {
            $location .= ': ' . $p['location_detail'];
        }
        foreach (['airport_garage_code', 'airport_parking_level', 'airport_parking_row'] as $field) {
            if (($p[$field] ?? '') !== '') {
                $location .= ' / ' . $this->human(str_replace('airport_', '', $field)) . ': ' . $p[$field];
            }
        }
        return ($p['location_label'] ?? 'Recorded position') . ' - ' . $location . ' · ' . $this->human($p['position_semantics']) . ' · observed ' . ($p['observed_at'] ?? $p['reported_at'] ?? 'Not recorded');
    }

    private function trip(?array $trip): string
    {
        return $trip === null ? 'None recorded' : 'Trip ' . $trip['id'] . ' · imported ' . $this->human($trip['trip_status_code'] ?? 'unknown') . ' · ' . ($trip['starts_at'] ?? 'Unknown pickup') . ' · ' . ($trip['ends_at'] ?? 'Unknown return');
    }

    private function event(?array $event): string
    {
        return $event === null ? 'No recorded basis event' : $this->human($event['event_code'] ?? $event['code'] ?? 'unknown') . ' #' . $event['id'] . ' · Trip ' . ($event['turo_trip_normalized_id'] ?? $event['trip_id'] ?? 'unlinked') . ' · ' . $event['occurred_at'];
    }

    private function human(string $value): string
    {
        return ucfirst(str_replace('_', ' ', $value));
    }

    private function url(array $filters): string
    {
        return '/operations/reconciliation?' . http_build_query(array_filter($filters, static fn ($v): bool => $v !== ''), '', '&', PHP_QUERY_RFC3986);
    }
}
