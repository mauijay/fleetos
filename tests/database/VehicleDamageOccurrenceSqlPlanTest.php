<?php

namespace Tests\Database;

use App\Database\VehicleDamageOccurrenceProvenanceSchema as Schema;
use CodeIgniter\Database\MySQLi\Connection;
use CodeIgniter\Test\CIUnitTestCase;
use FleetOS\Deployment\SqlAllowlist;
use FleetOS\Deployment\SqlExecutionGuard;

require_once dirname(__DIR__, 2) . '/tools/deployment/SqlExecutionGuard.php';

/** @internal Offline SQL identity only: this test never connects or executes DDL. */
final class VehicleDamageOccurrenceSqlPlanTest extends CIUnitTestCase
{
    public function testExactOrderedOfflineMariaDbPlanAndDeploymentGuard(): void
    {
        $db = new Connection(['hostname' => '127.0.0.1', 'port' => 1, 'database' => 'offline_never_connect', 'DBPrefix' => '']);
        $plan = Schema::statements($db);
        $this->assertFalse($db->connID);
        $this->assertCount(8, $plan);
        foreach ($plan as $sql) {
            $this->assertMatchesRegularExpression('/^(ALTER TABLE|CREATE INDEX) /', $sql);
            $this->assertDoesNotMatchRegularExpression('/\b(INSERT|UPDATE|DELETE|DROP|TRUNCATE)\s+(INTO|FROM|TABLE|INDEX)\b/i', $sql);
        }
        $manifest = SqlAllowlist::generate($plan);
        $this->assertTrue(SqlAllowlist::compare($manifest, $plan)['passed']);
        $executed = [];
        $guard = new SqlExecutionGuard($manifest);
        $guard->execute($plan, static function (string $sql) use (&$executed): bool {
            $executed[] = $sql;
            return true;
        });
        $this->assertSame($plan, $executed);
        $this->assertSame('completed', $guard->report()['state']);
        $this->assertTrue($guard->report()['writes_must_remain_suspended']);
        $windows = array_map(static fn (string $sql): string => $sql . "\r\n", $plan);
        $linux = array_map(static fn (string $sql): string => $sql . "\n", $plan);
        $this->assertTrue(SqlAllowlist::compare(SqlAllowlist::generate($windows), $linux)['passed']);
        foreach ([array_reverse($plan), array_slice($plan, 1), [...$plan, $plan[0]], array_map(static fn (string $sql): string => $sql . ' ', $plan)] as $invalid) {
            $rejected = new SqlExecutionGuard($manifest);
            try {
                $rejected->execute($invalid, static function (): bool {
                    throw new \LogicException('A rejected plan cannot reach execution.');
                });
                $this->fail('SQL plan drift must reject before execution.');
            } catch (\RuntimeException) {
                $this->assertSame('rejected_before_execution', $rejected->report()['state']);
                $this->assertSame([], $rejected->report()['completed_statement_numbers']);
            }
        }
        $prefixed = new Connection(['hostname' => '127.0.0.1', 'port' => 1, 'database' => 'offline_never_connect', 'DBPrefix' => 's1_']);
        $this->assertStringContainsString('`s1_vehicle_damage_incident_items`', Schema::statements($prefixed)[0]);
        $this->assertFalse($prefixed->connID);
        $directory = dirname(__DIR__, 2) . '/build/slice1';
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        file_put_contents($directory . '/mariadb-ddl-plan.json', json_encode($plan, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        file_put_contents($directory . '/mariadb-ddl-allowlist.json', json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        file_put_contents($directory . '/mariadb-ddl-guard-result.json', json_encode($guard->report(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }
}
