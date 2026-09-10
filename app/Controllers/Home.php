<?php

namespace App\Controllers;

class Home extends BaseController
{
    private const QUEUE_SCOPES = ['today', 'tomorrow', 'urgent'];
    private const MOVEMENT_FILTERS = ['readiness', 'additional', 'pickup', 'return', 'turnaround'];

    public function index(): string
    {
        $queueScope = $this->validatedQuery('queue', self::QUEUE_SCOPES);
        $movementFilter = $this->validatedQuery('movement', self::MOVEMENT_FILTERS);

        return view('fleet_command_center/index', [
            'commandCenter' => service('fleetCommandCenterViewModelService')->forToday(null, $queueScope, $movementFilter),
            'assets' => service('assetManifestService')->appAssets(),
        ]);
    }

    /** @param list<string> $allowed */
    private function validatedQuery(string $name, array $allowed): ?string
    {
        $value = $this->request->getGet($name);

        return is_string($value) && in_array($value, $allowed, true) ? $value : null;
    }
}
