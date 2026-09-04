<?php

namespace Config;

use App\Repositories\AirportMovementRepository;
use App\Repositories\FileRepository;
use App\Repositories\FleetIntelligenceRepository;
use App\Repositories\MovementChecklistRepository;
use App\Repositories\OperationalFactsRepository;
use App\Repositories\TuroAccessReimbursementRepository;
use App\Repositories\TuroImportErrorRepository;
use App\Repositories\TuroNormalizedTripRepository;
use App\Repositories\TuroVehicleMappingIssueRepository;
use App\Repositories\VehicleCapitalRepository;
use App\Repositories\VehicleTuroListingRepository;
use App\Services\Files\PrivateFileStorageService;
use App\Services\Fleet\AirportMovementWorkflowService;
use App\Services\Fleet\CurrentVehicleLocationService;
use App\Services\Fleet\DailyOperationsDashboardService;
use App\Services\Fleet\DecisionSupport\BusinessInsightService;
use App\Services\Fleet\DecisionSupport\DecisionSupportDashboardService;
use App\Services\Fleet\DecisionSupport\FleetOptimizationService;
use App\Services\Fleet\DecisionSupport\GuestRiskService;
use App\Services\Fleet\DecisionSupport\MaintenancePredictionService;
use App\Services\Fleet\DecisionSupport\PricingRecommendationService;
use App\Services\Fleet\DecisionSupport\RecommendationFactory;
use App\Services\Fleet\DecisionSupport\RevenueForecastService;
use App\Services\Fleet\FleetCommandCenterViewModelService;
use App\Services\Fleet\FleetCommandService;
use App\Services\Fleet\FleetHealthService;
use App\Services\Fleet\FleetStatisticsService;
use App\Services\Fleet\FleetVehicleService;
use App\Services\Fleet\LocationClassificationService;
use App\Services\Fleet\MovementAssessmentService;
use App\Services\Fleet\MovementEventService;
use App\Services\Fleet\MovementOperationalFactPresentationService;
use App\Services\Fleet\MovementOperationalFactService;
use App\Services\Fleet\NextConfirmedTripService;
use App\Services\Fleet\PlanningHorizonService;
use App\Services\Fleet\RevenueService;
use App\Services\Fleet\ScheduledLocationBackfillService;
use App\Services\Fleet\ScheduledMovementLocationService;
use App\Services\Fleet\TaskService;
use App\Services\Fleet\TripAnalyticsService;
use App\Services\Fleet\TripMovementChecklistService;
use App\Services\Fleet\TuroAccessReimbursementService;
use App\Services\Fleet\UnknownVehicleOnboardingService;
use App\Services\Fleet\VehicleAvailabilityService;
use App\Services\Fleet\VehicleCapitalService;
use App\Services\Fleet\VehicleOperationalProfileService;
use App\Services\Turo\TuroEarningsImportService;
use App\Services\Turo\TuroImportIssueService;
use App\Services\Turo\TuroTransactionRelinkingService;
use App\Services\Turo\TuroTripImportService;
use App\Services\Turo\TuroTripReconciliationService;
use App\Services\Turo\TuroVehicleMappingService;
use App\Services\View\AssetManifestService;
use CodeIgniter\Config\BaseService;

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
    public static function fleetVehicleService(bool $getShared = true): FleetVehicleService
    {
        if ($getShared) {
            return static::getSharedInstance('fleetVehicleService');
        }

        return new FleetVehicleService();
    }

    public static function vehicleCapitalRepository(bool $getShared = true): VehicleCapitalRepository
    {
        if ($getShared) {
            return static::getSharedInstance('vehicleCapitalRepository');
        }

        return new VehicleCapitalRepository();
    }

    public static function vehicleCapitalService(bool $getShared = true): VehicleCapitalService
    {
        if ($getShared) {
            return static::getSharedInstance('vehicleCapitalService');
        }

        return new VehicleCapitalService(repository: static::vehicleCapitalRepository());
    }

    public static function unknownVehicleOnboardingService(bool $getShared = true): UnknownVehicleOnboardingService
    {
        if ($getShared) {
            return static::getSharedInstance('unknownVehicleOnboardingService');
        }

        return new UnknownVehicleOnboardingService(
            static::fleetVehicleService(),
            static::turoVehicleMappingService(),
            static::turoTripReconciliationService(),
            static::turoTransactionRelinkingService(),
        );
    }

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

    public static function operationalFactsRepository(bool $getShared = true): OperationalFactsRepository
    {
        if ($getShared) {
            return static::getSharedInstance('operationalFactsRepository');
        }

        return new OperationalFactsRepository();
    }

    public static function movementEventService(bool $getShared = true): MovementEventService
    {
        if ($getShared) {
            return static::getSharedInstance('movementEventService');
        }

        return new MovementEventService(static::operationalFactsRepository());
    }

    public static function movementAssessmentService(bool $getShared = true): MovementAssessmentService
    {
        if ($getShared) {
            return static::getSharedInstance('movementAssessmentService');
        }

        return new MovementAssessmentService(static::operationalFactsRepository());
    }

    public static function currentVehicleLocationService(bool $getShared = true): CurrentVehicleLocationService
    {
        if ($getShared) {
            return static::getSharedInstance('currentVehicleLocationService');
        }

        return new CurrentVehicleLocationService(static::operationalFactsRepository());
    }

    public static function movementOperationalFactService(bool $getShared = true): MovementOperationalFactService
    {
        if ($getShared) {
            return static::getSharedInstance('movementOperationalFactService');
        }

        return new MovementOperationalFactService(null, static::movementEventService(), static::movementAssessmentService());
    }

    public static function movementOperationalFactPresentationService(bool $getShared = true): MovementOperationalFactPresentationService
    {
        if ($getShared) {
            return static::getSharedInstance('movementOperationalFactPresentationService');
        }

        return new MovementOperationalFactPresentationService(static::operationalFactsRepository());
    }

    public static function locationClassificationService(bool $getShared = true): LocationClassificationService
    {
        if ($getShared) {
            return static::getSharedInstance('locationClassificationService');
        }

        return new LocationClassificationService();
    }

    public static function scheduledMovementLocationService(bool $getShared = true): ScheduledMovementLocationService
    {
        if ($getShared) {
            return static::getSharedInstance('scheduledMovementLocationService');
        }

        return new ScheduledMovementLocationService(static::operationalFactsRepository(), static::locationClassificationService());
    }

    public static function scheduledLocationBackfillService(bool $getShared = true): ScheduledLocationBackfillService
    {
        if ($getShared) {
            return static::getSharedInstance('scheduledLocationBackfillService');
        }

        return new ScheduledLocationBackfillService(static::operationalFactsRepository(), static::locationClassificationService());
    }

    public static function vehicleOperationalProfileService(bool $getShared = true): VehicleOperationalProfileService
    {
        if ($getShared) {
            return static::getSharedInstance('vehicleOperationalProfileService');
        }

        return new VehicleOperationalProfileService(static::operationalFactsRepository());
    }

    public static function planningHorizonService(bool $getShared = true): PlanningHorizonService
    {
        if ($getShared) {
            return static::getSharedInstance('planningHorizonService');
        }

        return new PlanningHorizonService();
    }

    public static function nextConfirmedTripService(bool $getShared = true): NextConfirmedTripService
    {
        if ($getShared) {
            return static::getSharedInstance('nextConfirmedTripService');
        }

        return new NextConfirmedTripService(static::operationalFactsRepository(), static::planningHorizonService());
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

    public static function turoEarningsImportService(bool $getShared = true): TuroEarningsImportService
    {
        if ($getShared) {
            return static::getSharedInstance('turoEarningsImportService');
        }

        return new TuroEarningsImportService();
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

    public static function turoTransactionRelinkingService(bool $getShared = true): TuroTransactionRelinkingService
    {
        if ($getShared) {
            return static::getSharedInstance('turoTransactionRelinkingService');
        }

        return new TuroTransactionRelinkingService();
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
