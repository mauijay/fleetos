<?php

namespace App\Controllers;

use CodeIgniter\Config\Services as CoreServices;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\Shield\Config\Services as ShieldServices;
use Config\Services;
use RuntimeException;
use Throwable;

class TripExtraFulfillments extends BaseController
{
    public function complete(int $tripId, int $fulfillmentId): RedirectResponse
    {
        return $this->mutate($tripId, function () use ($tripId, $fulfillmentId): void {
            Services::tripExtraFulfillmentService()->complete(
                $this->activeCompanyId(),
                $tripId,
                $fulfillmentId,
                $this->actorUserId(),
                $this->request->getPost('completion_note'),
            );
        }, 'Extra fulfillment confirmed.');
    }

    public function reopen(int $tripId, int $fulfillmentId): RedirectResponse
    {
        return $this->mutate($tripId, function () use ($tripId, $fulfillmentId): void {
            Services::tripExtraFulfillmentService()->reopen($this->activeCompanyId(), $tripId, $fulfillmentId, $this->actorUserId());
        }, 'Extra fulfillment reopened.');
    }

    private function mutate(int $tripId, callable $action, string $success): RedirectResponse
    {
        $href = Services::operationalFactsRepository()->movementChecklistHref($tripId)
            ?? '/operations/trips/' . $tripId . '/commitments';
        try {
            $action();
        } catch (Throwable $exception) {
            return CoreServices::redirectresponse()->to($href . '#trip-preparation')
                ->with('movement_checklist_error', $exception->getMessage());
        }

        return CoreServices::redirectresponse()->to($href . '#trip-preparation')
            ->with('movement_checklist_notice', $success);
    }

    private function activeCompanyId(): int
    {
        $companyIds = Services::operationalFactsRepository()->activeFleetCompanyIds(date('Y-m-d'));
        if (count($companyIds) !== 1) {
            throw new RuntimeException('Extra fulfillment requires exactly one active fleet company context.');
        }

        return $companyIds[0];
    }

    private function actorUserId(): int
    {
        $user = ShieldServices::auth()->user();
        if ($user === null) {
            throw new RuntimeException('An authenticated operator is required.');
        }

        return (int) $user->id;
    }
}
