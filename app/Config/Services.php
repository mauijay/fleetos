<?php

namespace Config;

use App\Repositories\FleetIntelligenceRepository;
use App\Repositories\AirportMovementRepository;
use App\Repositories\FileRepository;
use App\Repositories\MovementChecklistRepository;
use App\Repositories\TuroAccessReimbursementRepository;
use App\Repositories\TuroImportErrorRepository;
use App\Repositories\TuroNormalizedTripRepository;
use App\Repositories\TuroVehicleMappingIssueRepository;
use App\Repositories\VehicleTuroListingRepository;
use App\Services\Fleet\DecisionSupport\BusinessInsightService;
use App\Services\Fleet\DecisionSupport\DecisionSupportDashboardService;
use App\Services\Fleet\DecisionSupport\FleetOptimizationService;
use App\Services\Fleet\DecisionSupport\GuestRiskService;
use App\Services\Fleet\DecisionSupport\MaintenancePredictionService;
use App\Services\Fleet\DecisionSupport\PricingRecommendationService;
use App\Services\Fleet\DecisionSupport\RecommendationFactory;
use App\Services\Fleet\DecisionSupport\RevenueForecastService;
use App\Services\Fleet\AirportMovementWorkflowService;
use App\Services\Fleet\DailyOperationsDashboardService;
use App\Services\Fleet\FleetCommandCenterViewModelService;
use App\Services\Fleet\FleetCommandService;
use App\Services\Fleet\FleetHealthService;
use App\Services\Fleet\FleetStatisticsService;
use App\Services\Fleet\TripMovementChecklistService;
use App\Services\Fleet\TuroAccessReimbursementService;
use App\Services\Fleet\RevenueService;
use App\Services\Fleet\TaskService;
use App\Services\Fleet\TripAnalyticsService;
use App\Services\Fleet\VehicleAvailabilityService;
use App\Services\Files\PrivateFileStorageService;
use App\Services\Turo\TuroImportIssueService;
use App\Services\Turo\TuroTripImportService;
use App\Services\Turo\TuroTripReconciliationService;
use App\Services\Turo\TuroVehicleMappingService;
use App\Services\View\AssetManifestService;
use CodeIgniter\Config\BaseService;
use Config\DecisionSupport;

/**
 * Services Configuration file.
 *
 * Services are simply other classes/libraries that the system uses
 * to do its job. This is used by CodeIgniter to allow the core of the
 * framework to be swapped out easily without affecting the usage within
 * the rest of your application.
 *
 * This file holds any application-specific services, or service overrides
 * that you might need. An example has been included with the general
 * method format you should use for your service methods. For more examples,
 * see the core Services file at system/Config/Services.php.
 */
class Services extends BaseService
{
    public static function fleetIntelligenceRepository(bool $getShared = true): FleetIntelligenceRepository
    {
        if ($getShared) {
            return static::getSharedInstance('fleetIntelligenceRepository');
        }

        return new FleetIntelligenceRepository();
    }

    public static function turoImportErrorRepository(bool $getShared = true): TuroImportErrorRepository
    {
        if ($getShared) {
            return static::getSharedInstance('turoImportErrorRepository');
        }

        return new TuroImportErrorRepository();
    }

    public static function movementChecklistRepository(bool $getShared = true): MovementChecklistRepository
    {
        if ($getShared) {
            return static::getSharedInstance('movementChecklistRepository');
        }

        return new MovementChecklistRepository();
    }

    public static function fileRepository(bool $getShared = true): FileRepository
    {
        if ($getShared) {
            return static::getSharedInstance('fileRepository');
        }

        return new FileRepository();
    }

    public static function privateFileStorageService(bool $getShared = true): PrivateFileStorageService
    {
        if ($getShared) {
            return static::getSharedInstance('privateFileStorageService');
        }

        return new PrivateFileStorageService(static::fileRepository());
    }

    public static function airportMovementRepository(bool $getShared = true): AirportMovementRepository
    {
        if ($getShared) {
            return static::getSharedInstance('airportMovementRepository');
        }

        return new AirportMovementRepository();
    }

    public static function turoAccessReimbursementRepository(bool $getShared = true): TuroAccessReimbursementRepository
    {
        if ($getShared) {
            return static::getSharedInstance('turoAccessReimbursementRepository');
        }

        return new TuroAccessReimbursementRepository();
    }

    public static function tripMovementChecklistService(bool $getShared = true): TripMovementChecklistService
    {
        if ($getShared) {
            return static::getSharedInstance('tripMovementChecklistService');
        }

        return new TripMovementChecklistService(static::movementChecklistRepository());
    }

    public static function airportMovementWorkflowService(bool $getShared = true): AirportMovementWorkflowService
    {
        if ($getShared) {
            return static::getSharedInstance('airportMovementWorkflowService');
        }

        return new AirportMovementWorkflowService(static::airportMovementRepository(), static::tripMovementChecklistService());
    }

    public static function turoAccessReimbursementService(bool $getShared = true): TuroAccessReimbursementService
    {
        if ($getShared) {
            return static::getSharedInstance('turoAccessReimbursementService');
        }

        return new TuroAccessReimbursementService(static::turoAccessReimbursementRepository(), static::privateFileStorageService());
    }

    public static function turoNormalizedTripRepository(bool $getShared = true): TuroNormalizedTripRepository
    {
        if ($getShared) {
            return static::getSharedInstance('turoNormalizedTripRepository');
        }

        return new TuroNormalizedTripRepository();
    }

    public static function vehicleTuroListingRepository(bool $getShared = true): VehicleTuroListingRepository
    {
        if ($getShared) {
            return static::getSharedInstance('vehicleTuroListingRepository');
        }

        return new VehicleTuroListingRepository();
    }

    public static function turoVehicleMappingIssueRepository(bool $getShared = true): TuroVehicleMappingIssueRepository
    {
        if ($getShared) {
            return static::getSharedInstance('turoVehicleMappingIssueRepository');
        }

        return new TuroVehicleMappingIssueRepository();
    }

    public static function turoImportIssueService(bool $getShared = true): TuroImportIssueService
    {
        if ($getShared) {
            return static::getSharedInstance('turoImportIssueService');
        }

        return new TuroImportIssueService(static::turoImportErrorRepository(), static::vehicleTuroListingRepository());
    }

    public static function turoVehicleMappingService(bool $getShared = true): TuroVehicleMappingService
    {
        if ($getShared) {
            return static::getSharedInstance('turoVehicleMappingService');
        }

        return new TuroVehicleMappingService(static::vehicleTuroListingRepository(), static::turoVehicleMappingIssueRepository());
    }

    public static function turoTripImportService(bool $getShared = true): TuroTripImportService
    {
        if ($getShared) {
            return static::getSharedInstance('turoTripImportService');
        }

        return new TuroTripImportService();
    }

    public static function turoTripReconciliationService(bool $getShared = true): TuroTripReconciliationService
    {
        if ($getShared) {
            return static::getSharedInstance('turoTripReconciliationService');
        }

        return new TuroTripReconciliationService(
            static::turoVehicleMappingIssueRepository(),
            static::turoImportErrorRepository(),
            static::turoNormalizedTripRepository(),
            static::turoTripImportService(),
        );
    }

    public static function revenueService(bool $getShared = true): RevenueService
    {
        if ($getShared) {
            return static::getSharedInstance('revenueService');
        }

        return new RevenueService(static::fleetIntelligenceRepository());
    }

    public static function fleetStatisticsService(bool $getShared = true): FleetStatisticsService
    {
        if ($getShared) {
            return static::getSharedInstance('fleetStatisticsService');
        }

        return new FleetStatisticsService(static::fleetIntelligenceRepository(), static::revenueService());
    }

    public static function fleetHealthService(bool $getShared = true): FleetHealthService
    {
        if ($getShared) {
            return static::getSharedInstance('fleetHealthService');
        }

        return new FleetHealthService(static::fleetIntelligenceRepository());
    }

    public static function vehicleAvailabilityService(bool $getShared = true): VehicleAvailabilityService
    {
        if ($getShared) {
            return static::getSharedInstance('vehicleAvailabilityService');
        }

        return new VehicleAvailabilityService(static::fleetIntelligenceRepository());
    }

    public static function tripAnalyticsService(bool $getShared = true): TripAnalyticsService
    {
        if ($getShared) {
            return static::getSharedInstance('tripAnalyticsService');
        }

        return new TripAnalyticsService(static::fleetIntelligenceRepository());
    }

    public static function taskService(bool $getShared = true): TaskService
    {
        if ($getShared) {
            return static::getSharedInstance('taskService');
        }

        return new TaskService(static::fleetIntelligenceRepository(), static::fleetHealthService());
    }

    public static function fleetCommandService(bool $getShared = true): FleetCommandService
    {
        if ($getShared) {
            return static::getSharedInstance('fleetCommandService');
        }

        return new FleetCommandService(
            static::fleetStatisticsService(),
            static::fleetHealthService(),
            static::vehicleAvailabilityService(),
            static::taskService(),
        );
    }

    public static function fleetCommandCenterViewModelService(bool $getShared = true): FleetCommandCenterViewModelService
    {
        if ($getShared) {
            return static::getSharedInstance('fleetCommandCenterViewModelService');
        }

        return new FleetCommandCenterViewModelService(
            static::fleetCommandService(),
            static::fleetStatisticsService(),
            static::fleetHealthService(),
            static::taskService(),
            static::vehicleAvailabilityService(),
            static::tripAnalyticsService(),
            static::decisionSupportDashboardService(),
            static::turoImportIssueService(),
            static::turoVehicleMappingService(),
            static::turoTripReconciliationService(),
            static::dailyOperationsDashboardService(),
        );
    }

    public static function dailyOperationsDashboardService(bool $getShared = true): DailyOperationsDashboardService
    {
        if ($getShared) {
            return static::getSharedInstance('dailyOperationsDashboardService');
        }

        return new DailyOperationsDashboardService(
            static::taskService(),
            static::vehicleAvailabilityService(),
            static::fleetHealthService(),
            static::fleetStatisticsService(),
            static::revenueService(),
            static::turoImportIssueService(),
            static::turoVehicleMappingService(),
            static::turoTripReconciliationService(),
            static::tripMovementChecklistService(),
            static::airportMovementWorkflowService(),
            static::turoAccessReimbursementService(),
        );
    }

    public static function assetManifestService(bool $getShared = true): AssetManifestService
    {
        if ($getShared) {
            return static::getSharedInstance('assetManifestService');
        }

        return new AssetManifestService();
    }

    public static function recommendationFactory(bool $getShared = true): RecommendationFactory
    {
        if ($getShared) {
            return static::getSharedInstance('recommendationFactory');
        }

        return new RecommendationFactory(config(DecisionSupport::class));
    }

    public static function pricingRecommendationService(bool $getShared = true): PricingRecommendationService
    {
        if ($getShared) {
            return static::getSharedInstance('pricingRecommendationService');
        }

        return new PricingRecommendationService(
            static::fleetStatisticsService(),
            static::revenueService(),
            config(DecisionSupport::class),
            static::recommendationFactory(),
        );
    }

    public static function fleetOptimizationService(bool $getShared = true): FleetOptimizationService
    {
        if ($getShared) {
            return static::getSharedInstance('fleetOptimizationService');
        }

        return new FleetOptimizationService(
            static::fleetStatisticsService(),
            config(DecisionSupport::class),
            static::recommendationFactory(),
        );
    }

    public static function maintenancePredictionService(bool $getShared = true): MaintenancePredictionService
    {
        if ($getShared) {
            return static::getSharedInstance('maintenancePredictionService');
        }

        return new MaintenancePredictionService(
            static::fleetHealthService(),
            config(DecisionSupport::class),
            static::recommendationFactory(),
        );
    }

    public static function guestRiskService(bool $getShared = true): GuestRiskService
    {
        if ($getShared) {
            return static::getSharedInstance('guestRiskService');
        }

        return new GuestRiskService(
            static::tripAnalyticsService(),
            config(DecisionSupport::class),
            static::recommendationFactory(),
        );
    }

    public static function revenueForecastService(bool $getShared = true): RevenueForecastService
    {
        if ($getShared) {
            return static::getSharedInstance('revenueForecastService');
        }

        return new RevenueForecastService(
            static::revenueService(),
            config(DecisionSupport::class),
            static::recommendationFactory(),
        );
    }

    public static function businessInsightService(bool $getShared = true): BusinessInsightService
    {
        if ($getShared) {
            return static::getSharedInstance('businessInsightService');
        }

        return new BusinessInsightService(
            static::fleetStatisticsService(),
            config(DecisionSupport::class),
            static::recommendationFactory(),
        );
    }

    public static function decisionSupportDashboardService(bool $getShared = true): DecisionSupportDashboardService
    {
        if ($getShared) {
            return static::getSharedInstance('decisionSupportDashboardService');
        }

        return new DecisionSupportDashboardService(
            static::pricingRecommendationService(),
            static::maintenancePredictionService(),
            static::fleetOptimizationService(),
            static::revenueForecastService(),
            static::guestRiskService(),
            static::businessInsightService(),
        );
    }
}
