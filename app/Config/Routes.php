<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->group('', ['filter' => 'session'], static function (RouteCollection $routes): void {
    $routes->post(
        'logout',
        '\\CodeIgniter\\Shield\\Controllers\\LoginController::logoutAction',
        ['as' => 'logout'],
    );
    $routes->get('/', 'Home::index');
    $routes->get('turo/imports', 'TuroImports::index', ['filter' => 'permission:admin.access']);
    $routes->post('turo/imports', 'TuroImports::store', ['filter' => 'permission:admin.access']);
    $routes->post('turo/earnings-imports', 'TuroImports::storeEarnings', ['filter' => 'permission:admin.access']);
    $routes->group('turo/extras', ['filter' => 'permission:admin.access'], static function (RouteCollection $routes): void {
        $routes->get('', 'TuroExtras::index');
        $routes->post('import', 'TuroExtras::import');
        $routes->post('catalog', 'TuroExtras::createExtra');
        $routes->post('catalog/(:num)', 'TuroExtras::updateExtra/$1');
        $routes->post('mappings', 'TuroExtras::mapSource');
        $routes->post('mappings/create-extra', 'TuroExtras::createAndMap');
    });
    $routes->get('turo/import-issues', 'TuroImportIssues::index', ['filter' => 'permission:admin.access']);
    $routes->post('turo/import-issues/(:num)/resolve', 'TuroImportIssues::resolve/$1', ['filter' => 'permission:admin.access']);
    $routes->post('turo/import-issues/(:num)/reopen', 'TuroImportIssues::reopen/$1', ['filter' => 'permission:admin.access']);
    $routes->get('turo/vehicle-matches', 'TuroVehicleMatches::index', ['filter' => 'permission:admin.access']);
    $routes->post('turo/vehicle-matches/map', 'TuroVehicleMatches::map', ['filter' => 'permission:admin.access']);
    $routes->get('turo/vehicle-matches/reprocess', 'TuroVehicleMatches::reprocessPreview', ['filter' => 'permission:admin.access']);
    $routes->post('turo/vehicle-matches/reprocess', 'TuroVehicleMatches::reprocess', ['filter' => 'permission:admin.access']);
    $routes->get('fleet/vehicles/(:num)/positioning-plan', 'VehiclePositioningPlans::show/$1', ['filter' => 'permission:admin.access']);
    $routes->post('fleet/vehicles/(:num)/positioning-plan', 'VehiclePositioningPlans::create/$1', ['filter' => 'permission:admin.access']);
    $routes->group('fleet/vehicles', ['filter' => 'permission:admin.access'], static function (RouteCollection $routes): void {
        $routes->get('', 'FleetVehicles::index');
        $routes->get('new', 'FleetVehicles::new');
        $routes->post('', 'FleetVehicles::create');
        $routes->get('(:num)', 'VehicleCapital::show/$1');
        $routes->get('(:num)/edit', 'FleetVehicles::edit/$1');
        $routes->post('(:num)', 'FleetVehicles::update/$1');
        $routes->post('(:num)/acquisition', 'VehicleCapital::saveAcquisition/$1');
        $routes->post('(:num)/lenders', 'VehicleCapital::createLender/$1');
        $routes->post('(:num)/loans', 'VehicleCapital::createLoan/$1');
        $routes->post('(:num)/loans/(:num)', 'VehicleCapital::updateLoan/$1/$2');
        $routes->post('(:num)/loans/(:num)/snapshots', 'VehicleCapital::saveSnapshot/$1/$2');
        $routes->post('(:num)/current-position', 'VehicleCurrentState::recordPosition/$1');
        $routes->post('(:num)/current-readiness', 'VehicleCurrentState::recordReadiness/$1');
        $routes->post('(:num)/health/tire-pressure', 'VehicleHealth::recordTirePressure/$1');
        $routes->post('(:num)/health/odometer', 'VehicleHealth::recordOdometer/$1');
        $routes->post('(:num)/health/tire-pressure-policy', 'VehicleHealth::saveTirePressurePolicy/$1');
        $routes->post('(:num)/health/tire-pressure-policy/disable', 'VehicleHealth::disableTirePressurePolicy/$1');
        $routes->post('(:num)/health/observations/(:num)/correct', 'VehicleHealth::correctObservation/$1/$2');
        $routes->post('(:num)/health/observations/(:num)/void', 'VehicleHealth::voidObservation/$1/$2');
        $routes->post('(:num)/damage', 'VehicleDamage::createForVehicle/$1');
        $routes->post('(:num)/damage/(:num)/correct', 'VehicleDamage::correct/$1/$2');
        $routes->post('(:num)/damage/(:num)/severity', 'VehicleDamage::changeSeverity/$1/$2');
        $routes->post('(:num)/damage/(:num)/worsen', 'VehicleDamage::worsenForVehicle/$1/$2');
        $routes->post('(:num)/damage/(:num)/status/(:segment)', 'VehicleDamage::transition/$1/$2/$3');
    });
    $routes->get('operations/checklists/(:num)', 'TripMovementChecklists::show/$1', ['filter' => 'permission:admin.access']);
    $routes->get('operations/vehicles/(:num)/trip-history', 'TripMovementChecklists::vehicleTripHistory/$1', ['filter' => 'permission:admin.access']);
    $routes->get('operations/trips/(:num)/commitments', 'TripCommitments::index/$1', ['filter' => 'permission:admin.access']);
    $routes->post('operations/trips/(:num)/commitments', 'TripCommitments::create/$1', ['filter' => 'permission:admin.access']);
    $routes->post('operations/trips/(:num)/commitments/(:num)/edit', 'TripCommitments::edit/$1/$2', ['filter' => 'permission:admin.access']);
    $routes->post('operations/trips/(:num)/commitments/(:num)/acknowledge', 'TripCommitments::acknowledge/$1/$2', ['filter' => 'permission:admin.access']);
    $routes->post('operations/trips/(:num)/commitments/(:num)/complete', 'TripCommitments::complete/$1/$2', ['filter' => 'permission:admin.access']);
    $routes->post('operations/trips/(:num)/commitments/(:num)/cancel', 'TripCommitments::cancel/$1/$2', ['filter' => 'permission:admin.access']);
    $routes->post('operations/trips/(:num)/extra-fulfillments/(:num)/complete', 'TripExtraFulfillments::complete/$1/$2', ['filter' => 'permission:admin.access']);
    $routes->post('operations/trips/(:num)/extra-fulfillments/(:num)/reopen', 'TripExtraFulfillments::reopen/$1/$2', ['filter' => 'permission:admin.access']);
    $routes->post('operations/trips/(:num)/actual-handoff', 'TripMovementChecklists::recordRetroactiveHandoff/$1', ['filter' => 'permission:admin.access']);
    $routes->post('operations/checklists/(:num)/facts', 'TripMovementChecklists::recordFacts/$1', ['filter' => 'permission:admin.access']);
    $routes->post('operations/checklists/(:num)/guest-return-staged', 'TripMovementChecklists::stageGuestReturn/$1', ['filter' => 'permission:admin.access']);
    $routes->post('operations/checklists/(:num)/guest-return-staged/correct', 'TripMovementChecklists::correctGuestReturn/$1', ['filter' => 'permission:admin.access']);
    $routes->post('operations/checklists/(:num)/guest-return-staged/void', 'TripMovementChecklists::voidGuestReturn/$1', ['filter' => 'permission:admin.access']);
    $routes->post('operations/checklists/(:num)/recover-vehicle', 'TripMovementChecklists::recoverVehicle/$1', ['filter' => 'permission:admin.access']);
    $routes->post('operations/checklists/(:num)/recovery-exceptions/(:num)/resolve', 'TripMovementChecklists::resolveRecoveryException/$1/$2', ['filter' => 'permission:admin.access']);
    $routes->post('operations/checklists/(:num)/damage', 'VehicleDamage::createForChecklist/$1', ['filter' => 'permission:admin.access']);
    $routes->post('operations/checklists/(:num)/damage/(:num)/worsen', 'VehicleDamage::worsenForChecklist/$1/$2', ['filter' => 'permission:admin.access']);
    $routes->post('operations/checklists/(:num)/recover-vehicle/void', 'TripMovementChecklists::voidRecoveredVehicle/$1', ['filter' => 'permission:admin.access']);
    $routes->post('operations/checklists/(:num)/stage-at-hnl', 'TripMovementChecklists::stageAtHnl/$1', ['filter' => 'permission:admin.access']);
    $routes->post('operations/checklists/(:num)/confirm-guest-pickup', 'TripMovementChecklists::confirmGuestPickup/$1', ['filter' => 'permission:admin.access']);
    $routes->post('operations/checklists/(:num)/vehicle-position', 'TripMovementChecklists::recordVehiclePosition/$1', ['filter' => 'permission:admin.access']);
    $routes->post('operations/checklists/(:num)/facts/correct', 'TripMovementChecklists::correctFacts/$1', ['filter' => 'permission:admin.access']);
    $routes->post('operations/checklists/(:num)/facts/repair-trip', 'TripMovementChecklists::repairWrongTrip/$1', ['filter' => 'permission:admin.access']);
    $routes->post('operations/checklists/(:num)/complete', 'TripMovementChecklists::complete/$1', ['filter' => 'permission:admin.access']);
    $routes->post('operations/checklists/(:num)/reopen', 'TripMovementChecklists::reopen/$1', ['filter' => 'permission:admin.access']);
    $routes->post('operations/checklist-items/(:num)/complete', 'TripMovementChecklists::completeItem/$1', ['filter' => 'permission:admin.access']);
    $routes->post('operations/checklist-items/(:num)/undo', 'TripMovementChecklists::undoItem/$1', ['filter' => 'permission:admin.access']);
    $routes->post('operations/checklist-items/(:num)/not-applicable', 'TripMovementChecklists::markNotApplicable/$1', ['filter' => 'permission:admin.access']);
    $routes->post('operations/checklists/(:num)/photos-complete', 'TripMovementChecklists::completePhotos/$1', ['filter' => 'permission:admin.access']);
    $routes->post('operations/checklists/(:num)/photos-undo', 'TripMovementChecklists::undoPhotos/$1', ['filter' => 'permission:admin.access']);
    $routes->post('operations/checklists/(:num)/charging-adapter-present', 'TripMovementChecklists::confirmChargingAdapter/$1', ['filter' => 'permission:admin.access']);
    $routes->post('operations/checklists/(:num)/charging-adapter-undo', 'TripMovementChecklists::undoChargingAdapter/$1', ['filter' => 'permission:admin.access']);
    $routes->post('operations/checklists/(:num)/disposition', 'TripMovementChecklists::setDisposition/$1', ['filter' => 'permission:admin.access']);
    $routes->get('operations/movement-locations', 'MovementLocationAliases::index', ['filter' => 'permission:admin.access']);
    $routes->post('operations/movement-locations', 'MovementLocationAliases::save', ['filter' => 'permission:admin.access']);
    $routes->get('operations/incidentals', 'Incidentals::index', ['filter' => 'permission:admin.access']);
    $routes->post('operations/incidentals/policies/(:num)/approve', 'Incidentals::approvePolicy/$1', ['filter' => 'permission:admin.access']);
    $routes->post('operations/incidentals/assignments', 'Incidentals::saveAssignment', ['filter' => 'permission:admin.access']);
    $routes->post('operations/incidentals/(:num)/plan', 'Incidentals::confirmPlan/$1', ['filter' => 'permission:admin.access']);
    $routes->post('operations/incidentals/(:num)/invoice-sent', 'Incidentals::invoiceSent/$1', ['filter' => 'permission:admin.access']);
    $routes->post('operations/incidentals/(:num)/no-invoice-needed', 'Incidentals::noInvoiceNeeded/$1', ['filter' => 'permission:admin.access']);
    $routes->group('operations/expenses', ['filter' => 'permission:admin.access'], static function (RouteCollection $routes): void {
        $routes->get('', 'OperatingExpenses::index');
        $routes->post('', 'OperatingExpenses::create');
        $routes->post('receipts', 'OperatingExpenses::uploadReceipt');
        $routes->get('receipts/(:num)/file', 'OperatingExpenses::receiptFile/$1');
        $routes->post('receipts/(:num)/classify', 'OperatingExpenses::classifyReceipt/$1');
        $routes->post('receipts/(:num)/non-business', 'OperatingExpenses::nonBusinessReceipt/$1');
        $routes->post('receipts/(:num)/duplicate', 'OperatingExpenses::duplicateReceipt/$1');
        $routes->post('receipts/(:num)/archive', 'OperatingExpenses::archiveReceipt/$1');
        $routes->get('(:num)', 'OperatingExpenses::show/$1');
        $routes->post('(:num)/correct', 'OperatingExpenses::correct/$1');
        $routes->post('(:num)/archive', 'OperatingExpenses::archive/$1');
        $routes->post('(:num)/restore', 'OperatingExpenses::restore/$1');
        $routes->post('(:num)/receipt', 'OperatingExpenses::attachReceipt/$1');
    });
    $routes->group('operations/airport', ['filter' => 'permission:admin.access'], static function (RouteCollection $routes): void {
        $routes->get('', 'AirportOperations::index');
        $routes->get('(:num)', 'AirportOperations::show/$1');
        $routes->post('(:num)/staging', 'AirportOperations::recordStaging/$1');
        $routes->post('(:num)/staged', 'AirportOperations::markStaged/$1');
        $routes->post('(:num)/instructions-sent', 'AirportOperations::markInstructionsSent/$1');
        $routes->post('(:num)/pickup-confirmed', 'AirportOperations::confirmPickup/$1');
        $routes->post('(:num)/return-location', 'AirportOperations::recordReturnLocation/$1');
        $routes->post('(:num)/vehicle-located', 'AirportOperations::confirmVehicleLocated/$1');
        $routes->post('(:num)/parking-cost', 'AirportOperations::recordParkingCost/$1');
        $routes->post('(:num)/complete', 'AirportOperations::complete/$1');
        $routes->post('(:num)/exception', 'AirportOperations::createException/$1');
        $routes->post('(:num)/turo-access-override', 'AirportOperations::createTuroAccessOverride/$1');
        $routes->get('reimbursements', 'AirportReimbursements::index');
        $routes->get('reimbursements/match/(:num)', 'AirportReimbursements::matchWorkspace/$1');
        $routes->get('reimbursements/receipts/(:num)/file', 'AirportReimbursements::receiptFile/$1');
        $routes->post('reimbursements/unmatched-receipt', 'AirportReimbursements::createUnmatchedReceipt');
        $routes->post('reimbursements/run-expense', 'AirportReimbursements::logRunExpense');
        $routes->post('reimbursements/(:num)/receipt', 'AirportReimbursements::attachReceipt/$1');
        $routes->post('reimbursements/receipts/(:num)/match', 'AirportReimbursements::matchReceipt/$1');
        $routes->post('reimbursements/receipts/(:num)/operations-expense', 'AirportReimbursements::assignOperationsExpense/$1');
        $routes->post('reimbursements/receipts/(:num)/classification', 'AirportReimbursements::classifyReceipt/$1');
        $routes->post('reimbursements/receipts/(:num)/metadata', 'AirportReimbursements::updateReceipt/$1');
        $routes->post('reimbursements/(:num)/filed', 'AirportReimbursements::markFiled/$1');
        $routes->post('reimbursements/(:num)/reimbursed', 'AirportReimbursements::markReimbursed/$1');
        $routes->post('reimbursements/(:num)/denied', 'AirportReimbursements::deny/$1');
    });
    $routes->group('reports/vehicle-financial-results', ['filter' => 'permission:admin.access'], static function (RouteCollection $routes): void {
        $routes->get('', 'VehicleFinancialResults::index');
        $routes->get('(:num)', 'VehicleFinancialResults::show/$1');
    });
});

service('auth')->routes($routes, ['except' => ['logout']]);
