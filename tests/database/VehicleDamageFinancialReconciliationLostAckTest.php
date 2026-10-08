<?php

use App\Repositories\VehicleDamageFinancialReconciliationRepository as Records;
use App\Services\Fleet\VehicleDamageFinancialReconciliationService as Reconcile;
use App\Services\Fleet\VehicleDamageRepairService as Work;
use CodeIgniter\Database\MigrationRunner;
use CodeIgniter\Database\Query;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use Config\Migrations;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\VehicleDamageDatabaseFixture;
use Tests\Support\VehicleDamageFinancialReconciliationFixture as Pair;

/** @internal Real file-backed SQLite COMMIT, independent receipt visibility and lost-ack recovery. */
final class VehicleDamageFinancialReconciliationLostAckTest extends CIUnitTestCase
{
    public static function commands(): array
    {
        return [['reconcileRepairCostToExpense'], ['invalidateFinancialReconciliation'], ['replaceFinancialReconciliation']];
    }

    #[DataProvider('commands')]
    public function testIndependentConnectionSeesActualCommitAndRecoversSameKeyOnce(string $method): void
    {
        $database = tempnam(dirname(__DIR__, 2) . '/build', 'b32a_synthetic_commit_');
        $config = (new Database())->tests;
        $config['database'] = $database;
        $paths = [$database];
        $db = Database::connect($config, false);
        $other = null;
        $listener = null;
        try {
            (new MigrationRunner(new Migrations(), $db))->setNamespace('App')->latest();
            VehicleDamageDatabaseFixture::seed($db);
            $pair = Pair::pair($db, $paths);
            $payload = $pair['payload'];
            $work = new Work($db);
            if ($method !== 'reconcileRepairCostToExpense') {
                $created = $work->reconcileRepairCostToExpense(1, 10, $pair['job'], $payload, 7);
                $this->assertTrue($created['success']);
                $row = (new Records($db))->rows(1, 10, $pair['job'])[0];
                $payload = array_replace($payload, ['command_key' => Work::commandKey(), 'expected_version' => $created['version'], 'reconciliation_id' => $row['id'], 'expected_reconciliation_state' => Reconcile::state($row)]);
            }
            $injected = false;
            $listener = static function (Query $query) use (&$injected): void {
                if (! $injected && strtoupper(trim($query->getQuery())) === 'COMMIT') {
                    $injected = true;
                    throw new RuntimeException('Synthetic acknowledgement lost after actual COMMIT');
                }
            };
            Events::on('DBQuery', $listener);
            $uncertain = $work->{$method}(1, 10, $pair['job'], $payload, 7);
            Events::removeListener('DBQuery', $listener);
            $listener = null;
            $this->assertTrue($injected);
            $this->assertTrue($uncertain['uncertain']);
            $other = Database::connect($config, false);
            $snapshot = function () use ($other): array {
                $result = [];
                foreach ([Records::TABLE, 'vehicle_damage_repair_jobs', 'vehicle_damage_repair_job_events', 'audit_logs'] as $table) {
                    $result[$table] = $other->table($table)->orderBy('id')->get()->getResultArray();
                }
                return $result;
            };
            $before = $snapshot();
            $this->assertNotEmpty($before[Records::TABLE]);
            $replay = (new Work($other))->{$method}(1, 10, $pair['job'], $payload, 7);
            $this->assertTrue($replay['success'], json_encode($replay));
            $this->assertTrue($replay['replayed']);
            $this->assertSame($before, $snapshot());
        } finally {
            if ($listener !== null) {
                Events::removeListener('DBQuery', $listener);
            }
            $other?->close();
            $db->close();
            foreach ($paths as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }
}
