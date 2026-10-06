<?php

namespace Tests\Support;

use App\Database\Seeds\LookupSeeder;
use App\Repositories\LookupRepository;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\MigrationRunner;
use Config\Database;
use Config\Migrations;
use RuntimeException;

/** Synthetic damage fixtures on the application's migrations, never a parallel schema. */
final class VehicleDamageDatabaseFixture
{
    public static function migrate(BaseConnection $db, bool $withIncidents = true): void
    {
        if ($db->DBDriver !== 'SQLite3' || $db->database !== ':memory:') {
            throw new RuntimeException('Damage fixtures require an isolated in-memory SQLite database.');
        }
        $runner = new MigrationRunner(new Migrations(), $db);
        $runner->setNamespace('App');
        if ($withIncidents) {
            $runner->latest();
        } else {
            $paths = glob(__DIR__ . '/../../app/Database/Migrations/*.php');
            sort($paths);
            foreach ($paths as $path) {
                if (strcmp(basename($path), '2026-09-26') < 0) {
                    $runner->force($path, 'App');
                }
            }
        }
        if ($db->fieldExists('company_id', 'turo_trips_normalized')) {
            throw new RuntimeException('Normalized trip ownership must come from its vehicle.');
        }
    }

    public static function seed(BaseConnection $db): void
    {
        (new LookupSeeder(new Database(), $db))->run();
        $db->table('companies')->insertBatch([
            ['id' => 1, 'name' => 'Synthetic Company A', 'slug' => 'synthetic-company-a'],
            ['id' => 2, 'name' => 'Synthetic Company B', 'slug' => 'synthetic-company-b'],
        ]);
        $db->table('vehicle_makes')->insert(['id' => 1, 'code' => 'synthetic-make', 'name' => 'Synthetic Make']);
        $db->table('vehicle_models')->insert(['id' => 1, 'vehicle_make_id' => 1, 'code' => 'synthetic-model', 'name' => 'Synthetic Model']);
        $db->table('vehicle_body_styles')->insert(['code' => 'synthetic-body', 'name' => 'Synthetic Body']);
        $body = (int) $db->insertID();
        $db->table('vehicle_colors')->insert(['code' => 'synthetic-color', 'name' => 'Synthetic Color']);
        $color = (int) $db->insertID();
        $db->table('vehicle_specs')->insert([
            'id' => 1, 'vehicle_model_id' => 1, 'model_year' => 2026,
            'vehicle_body_style_id' => $body, 'exterior_vehicle_color_id' => $color, 'interior_vehicle_color_id' => $color,
        ]);
        foreach ([[10, 1], [11, 1], [20, 2]] as [$id, $company]) {
            $db->table('fleet_vehicles')->insert([
                'id' => $id, 'company_id' => $company, 'vehicle_spec_id' => 1,
                'vehicle_trim_level_id' => 1, 'vehicle_drivetrain_id' => 1, 'vehicle_status_id' => 1,
                'fleet_code' => 'SYNTHETIC-DAMAGE-' . $id, 'display_name' => 'Synthetic Vehicle ' . $id,
            ]);
        }
        foreach ([[100, 10], [102, 10], [101, 11], [200, 20]] as [$id, $vehicle]) {
            $db->table('turo_trips_normalized')->insert([
                'id' => $id, 'fleet_vehicle_id' => $vehicle,
                'turo_trip_id' => 'SYNTHETIC-TRIP-' . $id, 'turo_reservation_id' => 'SYNTHETIC-RESERVATION-' . $id,
                'starts_at' => '2026-09-25 08:00:00', 'ends_at' => '2026-09-27 08:00:00',
            ]);
        }
        foreach ([[1000, 100, 10], [1002, 102, 10], [1001, 101, 11]] as [$id, $trip, $vehicle]) {
            $db->table('trip_movement_events')->insert([
                'id' => $id, 'company_id' => 1, 'turo_trip_normalized_id' => $trip, 'fleet_vehicle_id' => $vehicle,
                'event_code' => 'vehicle_recovered', 'occurred_at' => '2026-09-25 09:00:00',
                'source' => 'operator', 'actor_user_id' => 7,
            ]);
        }
        foreach ([[500, 100, 10, 1000], [501, 101, 11, 1001]] as [$id, $trip, $vehicle, $event]) {
            $db->table('vehicle_recovery_exceptions')->insert([
                'id' => $id, 'company_id' => 1, 'turo_trip_normalized_id' => $trip, 'fleet_vehicle_id' => $vehicle,
                'trip_movement_event_id' => $event, 'exception_code' => 'damage', 'note' => 'Synthetic recovery damage',
                'status' => 'open', 'created_by' => 7, 'created_at' => '2026-09-25 09:00:00',
            ]);
        }
        $status = (new LookupRepository($db))->valueId('claim_status', 'open');
        foreach ([[700, 10], [701, 11]] as [$id, $vehicle]) {
            $db->table('damage_claims')->insert([
                'id' => $id, 'fleet_vehicle_id' => $vehicle,
                'claim_status_lookup_value_id' => $status, 'claim_number' => 'SYNTHETIC-CLAIM-' . $id,
            ]);
        }
        $db->table('files')->insertBatch([
            ['id' => 800, 'path' => 'private/synthetic-file-800', 'original_filename' => 'synthetic-damage.pdf'],
            ['id' => 801, 'path' => 'private/synthetic-file-801', 'original_filename' => 'synthetic-other.pdf'],
        ]);
        $db->table('images')->insertBatch([
            ['id' => 900, 'path' => 'private/synthetic-image-900', 'alt_text' => 'Synthetic damage image'],
            ['id' => 901, 'path' => 'private/synthetic-image-901', 'alt_text' => 'Synthetic other image'],
        ]);
        $lookups = new LookupRepository($db);
        foreach ([[1, 10, 800], [2, 11, 801]] as [$id, $vehicle, $file]) {
            $db->table('vehicle_files')->insert([
                'id' => $id, 'fleet_vehicle_id' => $vehicle, 'file_id' => $file,
                'file_type_lookup_value_id' => $lookups->valueId('file_type', 'claim'),
            ]);
        }
        foreach ([[1, 10, 900], [2, 11, 901]] as [$id, $vehicle, $image]) {
            $db->table('vehicle_images')->insert([
                'id' => $id, 'fleet_vehicle_id' => $vehicle, 'image_id' => $image,
                'image_type_lookup_value_id' => $lookups->valueId('image_type', 'damage'),
            ]);
        }
    }
}
