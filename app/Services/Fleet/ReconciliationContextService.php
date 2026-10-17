<?php

namespace App\Services\Fleet;

use App\DTOs\Fleet\OperationalReconciliation;

/** Request-local presentation over the accepted projection. No reads, writes or issue detection. */
final class ReconciliationContextService
{
    private array $trips = [];
    private array $checklists = [];
    private array $issues = [];

    public function __construct(private readonly OperationalReconciliation $projection)
    {
        foreach ($projection->vehicles as $vehicleId => $vehicle) {
            // The engine emits one commitment summary for every owned, nondeleted trip.
            foreach ($vehicle['guest_commitments'] as $trip) {
                $this->trips[$trip['trip_id']] = (int) $vehicleId;
            }
            foreach ($vehicle['checklists'] as $checklist) {
                $this->checklists[$checklist['id']] = $checklist;
            }
            foreach ($vehicle['issues'] as $issue) {
                $this->issues[$issue['fingerprint']] = $issue;
            }
        }
    }

    /** Validate the complete entity chain before filtering or constructing a return link. */
    public function target(array $query): array
    {
        $filters = array_fill_keys(['trip', 'checklist', 'issue', 'return'], '');
        $ids = [];
        $valid = true;
        foreach (['vehicle', 'trip', 'checklist'] as $key) {
            $value = $query[$key] ?? '';
            $valid = $valid && is_string($value) && ($value === '' || (bool) preg_match('/^[1-9][0-9]{0,17}$/D', $value));
            $ids[$key] = is_string($value) && ctype_digit($value) ? (int) $value : 0;
        }
        $vehicleId = $ids['vehicle'];
        $tripId = $ids['trip'];
        $checklist = $this->checklists[$ids['checklist']] ?? null;
        if ($ids['checklist'] > 0) {
            $valid = $valid && $checklist !== null && ($tripId === 0 || $tripId === $checklist['trip_id']);
            $tripId = $checklist['trip_id'] ?? 0;
        }
        if ($tripId > 0) {
            $owner = $this->trips[$tripId] ?? 0;
            $valid = $valid && $owner > 0 && ($vehicleId === 0 || $owner === $vehicleId);
            $vehicleId = $owner;
        }
        $valid = $valid && ($vehicleId === 0 || isset($this->projection->vehicles[$vehicleId]));
        $fingerprint = $query['issue'] ?? '';
        $issue = is_string($fingerprint) ? ($this->issues[$fingerprint] ?? null) : null;
        if ($fingerprint !== '') {
            $valid = $valid && $issue !== null && ($vehicleId === 0 || $vehicleId === $issue['vehicle_id'])
                && ($tripId === 0 || in_array($tripId, $issue['trip_ids'], true));
        }
        $return = $query['return'] ?? '';
        $returnLink = match ($return) {
            '' => null,
            'vehicle' => $vehicleId > 0 ? ['href' => '/fleet/vehicles/' . $vehicleId, 'label' => 'Return to vehicle details'] : null,
            'history' => $vehicleId > 0 ? ['href' => '/operations/vehicles/' . $vehicleId . '/trip-history' . ($tripId > 0 ? '?trip=' . $tripId : ''), 'label' => 'Return to trip history'] : null,
            'trip' => $tripId > 0 ? ['href' => '/operations/trips/' . $tripId . '/commitments', 'label' => 'Return to trip commitments'] : null,
            'checklist' => $checklist !== null ? ['href' => '/operations/checklists/' . $checklist['id'], 'label' => 'Return to movement checklist'] : null,
            default => null,
        };
        $valid = $valid && ($return === '' || $returnLink !== null);
        if (! $valid) {
            return ['filters' => $filters, 'errors' => ['Choose an available reconciliation context.'], 'return_link' => null, 'vehicle_id' => 0, 'trip_id' => 0, 'label' => null];
        }
        foreach (['trip', 'checklist'] as $key) {
            $filters[$key] = $ids[$key] > 0 ? (string) $ids[$key] : '';
        }
        $filters['issue'] = $fingerprint;
        $filters['return'] = $return;
        $label = $checklist !== null ? 'Checklist #' . $checklist['id'] . ' / Trip ' . $tripId . ' context' : ($tripId > 0 ? 'Trip ' . $tripId : null);
        return ['filters' => $filters, 'errors' => [], 'return_link' => $returnLink, 'vehicle_id' => $vehicleId, 'trip_id' => $tripId, 'label' => $label];
    }

    public function matches(array $issue, array $target): bool
    {
        return $target['errors'] === [] && ($target['vehicle_id'] === 0 || $issue['vehicle_id'] === $target['vehicle_id'])
            && ($target['trip_id'] === 0 || in_array($target['trip_id'], $issue['trip_ids'], true))
            && ($target['filters']['issue'] === '' || $issue['fingerprint'] === $target['filters']['issue']);
    }

    /** Caller has loaded the engine once for all entities in this request. */
    public function badge(int $vehicleId, ?int $tripId = null, ?int $checklistId = null, string $return = 'vehicle'): ?array
    {
        $query = ['vehicle' => (string) $vehicleId, 'return' => $return];
        if ($tripId !== null) {
            $query['trip'] = (string) $tripId;
        }
        if ($checklistId !== null) {
            $query['checklist'] = (string) $checklistId;
        }
        $target = $this->target($query);
        $issues = array_values(array_filter($this->projection->vehicles[$vehicleId]['issues'] ?? [], fn (array $issue): bool => $this->matches($issue, $target)));
        if ($issues === []) {
            return null;
        }
        $severities = array_column($issues, 'severity');
        sort($severities);
        if (count($issues) === 1) {
            $query['issue'] = $issues[0]['fingerprint'];
        }
        return ['count' => count($issues), 'severity' => $severities[0], 'scopes' => array_values(array_unique(array_column($issues, 'scope'))),
            'entity' => $target['label'] ?? 'Vehicle findings', 'href' => '/operations/reconciliation?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986)];
    }
}
