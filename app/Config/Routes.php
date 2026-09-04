<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->group('', ['filter' => 'session'], static function (RouteCollection $routes): void {
	$routes->get('/', 'Home::index');
	$routes->get('turo/imports', 'TuroImports::index', ['filter' => 'permission:admin.access']);
	$routes->post('turo/imports', 'TuroImports::store', ['filter' => ['permission:admin.access', 'csrf']]);
	$routes->post('turo/earnings-imports', 'TuroImports::storeEarnings', ['filter' => ['permission:admin.access', 'csrf']]);
	$routes->get('turo/import-issues', 'TuroImportIssues::index', ['filter' => 'permission:admin.access']);
	$routes->post('turo/import-issues/(:num)/resolve', 'TuroImportIssues::resolve/$1', ['filter' => ['permission:admin.access', 'csrf']]);
	$routes->post('turo/import-issues/(:num)/reopen', 'TuroImportIssues::reopen/$1', ['filter' => ['permission:admin.access', 'csrf']]);
	$routes->get('turo/vehicle-matches', 'TuroVehicleMatches::index', ['filter' => 'permission:admin.access']);
	$routes->post('turo/vehicle-matches/map', 'TuroVehicleMatches::map', ['filter' => ['permission:admin.access', 'csrf']]);
	$routes->get('turo/vehicle-matches/reprocess', 'TuroVehicleMatches::reprocessPreview', ['filter' => 'permission:admin.access']);
	$routes->post('turo/vehicle-matches/reprocess', 'TuroVehicleMatches::reprocess', ['filter' => ['permission:admin.access', 'csrf']]);
	$routes->get('fleet/vehicles/(:num)/positioning-plan', 'VehiclePositioningPlans::show/$1', ['filter' => 'permission:admin.access']);
	$routes->post('fleet/vehicles/(:num)/positioning-plan', 'VehiclePositioningPlans::create/$1', ['filter' => ['permission:admin.access', 'csrf']]);
	$routes->group('fleet/vehicles', ['filter' => 'permission:admin.access'], static function (RouteCollection $routes): void {
		$routes->get('', 'FleetVehicles::index');
		$routes->get('new', 'FleetVehicles::new');
		$routes->post('', 'FleetVehicles::create', ['filter' => 'csrf']);
		$routes->get('(:num)', 'VehicleCapital::show/$1');
		$routes->get('(:num)/edit', 'FleetVehicles::edit/$1');
		$routes->post('(:num)', 'FleetVehicles::update/$1', ['filter' => 'csrf']);
		$routes->post('(:num)/acquisition', 'VehicleCapital::saveAcquisition/$1', ['filter' => 'csrf']);
		$routes->post('(:num)/lenders', 'VehicleCapital::createLender/$1', ['filter' => 'csrf']);
		$routes->post('(:num)/loans', 'VehicleCapital::createLoan/$1', ['filter' => 'csrf']);
		$routes->post('(:num)/loans/(:num)', 'VehicleCapital::updateLoan/$1/$2', ['filter' => 'csrf']);
		$routes->post('(:num)/loans/(:num)/snapshots', 'VehicleCapital::saveSnapshot/$1/$2', ['filter' => 'csrf']);
	});
	$routes->get('operations/checklists/(:num)', 'TripMovementChecklists::show/$1', ['filter' => 'permission:admin.access']);
	$routes->post('operations/checklists/(:num)/facts', 'TripMovementChecklists::recordFacts/$1', ['filter' => ['permission:admin.access', 'csrf']]);
	$routes->post('operations/checklists/(:num)/facts/correct', 'TripMovementChecklists::correctFacts/$1', ['filter' => ['permission:admin.access', 'csrf']]);
	$routes->post('operations/checklists/(:num)/complete', 'TripMovementChecklists::complete/$1', ['filter' => ['permission:admin.access', 'csrf']]);
	$routes->post('operations/checklists/(:num)/reopen', 'TripMovementChecklists::reopen/$1', ['filter' => ['permission:admin.access', 'csrf']]);
	$routes->post('operations/checklist-items/(:num)/complete', 'TripMovementChecklists::completeItem/$1', ['filter' => ['permission:admin.access', 'csrf']]);
	$routes->post('operations/checklist-items/(:num)/undo', 'TripMovementChecklists::undoItem/$1', ['filter' => ['permission:admin.access', 'csrf']]);
	$routes->post('operations/checklist-items/(:num)/not-applicable', 'TripMovementChecklists::markNotApplicable/$1', ['filter' => ['permission:admin.access', 'csrf']]);
	$routes->post('operations/checklists/(:num)/disposition', 'TripMovementChecklists::setDisposition/$1', ['filter' => ['permission:admin.access', 'csrf']]);
	$routes->get('operations/movement-locations', 'MovementLocationAliases::index', ['filter' => 'permission:admin.access']);
	$routes->post('operations/movement-locations', 'MovementLocationAliases::save', ['filter' => ['permission:admin.access', 'csrf']]);
	$routes->get('operations/airport', 'AirportOperations::index');
	$routes->get('operations/airport/(:num)', 'AirportOperations::show/$1');
	$routes->post('operations/airport/(:num)/staging', 'AirportOperations::recordStaging/$1');
	$routes->post('operations/airport/(:num)/staged', 'AirportOperations::markStaged/$1');
	$routes->post('operations/airport/(:num)/instructions-sent', 'AirportOperations::markInstructionsSent/$1');
	$routes->post('operations/airport/(:num)/pickup-confirmed', 'AirportOperations::confirmPickup/$1');
	$routes->post('operations/airport/(:num)/return-location', 'AirportOperations::recordReturnLocation/$1');
	$routes->post('operations/airport/(:num)/vehicle-located', 'AirportOperations::confirmVehicleLocated/$1');
	$routes->post('operations/airport/(:num)/parking-cost', 'AirportOperations::recordParkingCost/$1');
	$routes->post('operations/airport/(:num)/complete', 'AirportOperations::complete/$1');
	$routes->post('operations/airport/(:num)/exception', 'AirportOperations::createException/$1');
	$routes->post('operations/airport/(:num)/turo-access-override', 'AirportOperations::createTuroAccessOverride/$1');
	$routes->get('operations/airport/reimbursements', 'AirportReimbursements::index');
	$routes->get('operations/airport/reimbursements/match/(:num)', 'AirportReimbursements::matchWorkspace/$1');
	$routes->post('operations/airport/reimbursements/unmatched-receipt', 'AirportReimbursements::createUnmatchedReceipt');
	$routes->post('operations/airport/reimbursements/run-expense', 'AirportReimbursements::logRunExpense');
	$routes->post('operations/airport/reimbursements/(:num)/receipt', 'AirportReimbursements::attachReceipt/$1');
	$routes->post('operations/airport/reimbursements/receipts/(:num)/match', 'AirportReimbursements::matchReceipt/$1');
	$routes->post('operations/airport/reimbursements/receipts/(:num)/operations-expense', 'AirportReimbursements::assignOperationsExpense/$1');
	$routes->post('operations/airport/reimbursements/receipts/(:num)/classification', 'AirportReimbursements::classifyReceipt/$1');
	$routes->post('operations/airport/reimbursements/receipts/(:num)/metadata', 'AirportReimbursements::updateReceipt/$1');
	$routes->post('operations/airport/reimbursements/(:num)/filed', 'AirportReimbursements::markFiled/$1');
	$routes->post('operations/airport/reimbursements/(:num)/reimbursed', 'AirportReimbursements::markReimbursed/$1');
	$routes->post('operations/airport/reimbursements/(:num)/denied', 'AirportReimbursements::deny/$1');
	$routes->get('files/receipts/(:num)', 'SecureFiles::receipt/$1');
});

service('auth')->routes($routes);
