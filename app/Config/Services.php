<?php

namespace Config;

use App\Repositories\FleetIntelligenceRepository;
use App\Services\Fleet\FleetCommandService;
use App\Services\Fleet\FleetHealthService;
use App\Services\Fleet\FleetStatisticsService;
use App\Services\Fleet\RevenueService;
use App\Services\Fleet\TaskService;
use App\Services\Fleet\TripAnalyticsService;
use App\Services\Fleet\VehicleAvailabilityService;
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
    public static function fleetIntelligenceRepository(bool $getShared = true): FleetIntelligenceRepository
    {
        if ($getShared) {
            return static::getSharedInstance('fleetIntelligenceRepository');
        }

        return new FleetIntelligenceRepository();
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
}
