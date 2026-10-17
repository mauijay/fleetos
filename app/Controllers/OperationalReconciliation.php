<?php

namespace App\Controllers;

use App\Services\Fleet\OperationalReconciliationViewModel;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

final class OperationalReconciliation extends BaseController
{
    public function index(): ResponseInterface
    {
        $companyIds = Services::operationalFactsRepository()->activeFleetCompanyIds(date('Y-m-d'));
        if (count($companyIds) !== 1) {
            return $this->response->setStatusCode(403)->setBody('A single active operator company context is required.');
        }
        $projection = Services::fleetOperationalReconciliationService()->forCompany($companyIds[0]);
        $model = (new OperationalReconciliationViewModel())->present($projection, $this->request->getGet() ?? []);
        return $this->response->setStatusCode($model['errors'] === [] ? 200 : 400)->setBody(\CodeIgniter\Config\Services::renderer()->setData([
            'reconciliation' => $model,
            'assets' => Services::assetManifestService()->appAssets(),
        ])->render('operational_reconciliation/index'));
    }
}
