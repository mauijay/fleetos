<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('/', 'Home::index');
$routes->get('turo/imports', 'TuroImports::index');
$routes->post('turo/imports', 'TuroImports::store');
$routes->get('turo/import-issues', 'TuroImportIssues::index');
$routes->post('turo/import-issues/(:num)/resolve', 'TuroImportIssues::resolve/$1');
$routes->post('turo/import-issues/(:num)/reopen', 'TuroImportIssues::reopen/$1');
$routes->get('turo/vehicle-matches', 'TuroVehicleMatches::index');
$routes->post('turo/vehicle-matches/map', 'TuroVehicleMatches::map');
$routes->get('turo/vehicle-matches/reprocess', 'TuroVehicleMatches::reprocessPreview');
$routes->post('turo/vehicle-matches/reprocess', 'TuroVehicleMatches::reprocess');
$routes->get('operations/checklists/(:num)', 'TripMovementChecklists::show/$1');
$routes->post('operations/checklists/(:num)/complete', 'TripMovementChecklists::complete/$1');
$routes->post('operations/checklists/(:num)/reopen', 'TripMovementChecklists::reopen/$1');
$routes->post('operations/checklist-items/(:num)/complete', 'TripMovementChecklists::completeItem/$1');
$routes->post('operations/checklist-items/(:num)/undo', 'TripMovementChecklists::undoItem/$1');
$routes->post('operations/checklist-items/(:num)/not-applicable', 'TripMovementChecklists::markNotApplicable/$1');
$routes->post('operations/checklists/(:num)/disposition', 'TripMovementChecklists::setDisposition/$1');
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

service('auth')->routes($routes);
