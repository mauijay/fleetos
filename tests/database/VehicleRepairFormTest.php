<?php

use App\Database\VehicleDamageOccurrenceProvenanceSchema;
use CodeIgniter\Config\Services as CoreServices;
use CodeIgniter\Database\MigrationRunner;
use CodeIgniter\Database\Query;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Shield\Test\AuthenticationTesting;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;
use Config\Migrations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\Support\VehicleDamageRepairDatabaseFixture;
use Tests\Support\VehicleRepairFormFixture as Fixture;

/** @internal */
#[PreserveGlobalState(false)]
#[RunTestsInSeparateProcesses]
final class VehicleRepairFormTest extends CIUnitTestCase
{
    use AuthenticationTesting;
    use FeatureTestTrait;

    public static function cases(): array
    {
        return array_map(static fn (string $case): array => [$case], Fixture::CASES);
    }

    #[DataProvider('cases')]
    public function testOwnedSelectionGetRendersWithoutWarningsAndNeverWrites(string $case): void
    {
        $_SESSION = [];
        $db = Database::connect('tests');
        VehicleDamageRepairDatabaseFixture::prepare($db);
        Fixture::seed($db, $case);
        $db->table('fleet_vehicles')->where('id', 20)->update(['out_of_service_date' => '1900-01-01']);
        $runner = new MigrationRunner(new Migrations(), $db);
        $runner->setNamespace('CodeIgniter\\Shield')->latest();
        $runner->setNamespace('CodeIgniter\\Settings')->latest();
        $users = new UserModel();
        $user = new User(['username' => 'synthetic-repair-form-operator', 'active' => 1]);
        $users->save($user);
        $user = $users->findById($users->getInsertID());
        $user->addPermission('admin.access');
        $this->actingAs($user);
        CoreServices::routes()->resetRoutes();
        CoreServices::routes()->loadRoutes();
        $this->withRoutes();
        // Keep real auth/permission filters; omit only Shield's activity timestamp
        // in this synthetic fixture so the SQL guard can require entirely read-only GETs.
        $authConfig = new \Config\Auth();
        $authConfig->recordActiveDate = false;
        \CodeIgniter\Config\Factories::injectMock('config', 'Auth', $authConfig);
        $runner->setNamespace('App');
        $uids = array_map($runner->getObjectUid(...), $runner->getHistory(''));
        $this->assertSame([], array_values(array_filter($runner->findMigrations(), static fn ($m): bool => ! in_array($m->uid, $uids, true))));
        $this->assertTrue(VehicleDamageOccurrenceProvenanceSchema::ready($db));
        $this->assertTrue((new \App\Repositories\VehicleDamageOccurrenceRepository($db))->ready());
        $before = Fixture::rows($db);
        $property = new ReflectionProperty($db, 'queryClass');
        $oldQueryClass = $property->getValue($db);
        $property->setValue($db, new class ($db) extends Query {
            public function getQuery(): string
            {
                $sql = parent::getQuery();
                $readPragma = preg_match('/^\s*PRAGMA\s+(table_info|table_xinfo|index_list|index_info|foreign_key_list)\s*\([^;]*\)\s*$/i', $sql);
                if ((! preg_match('/^\s*(SELECT|SHOW)\b/i', $sql) && ! $readPragma) || preg_match('/\bINTO\s+(OUTFILE|DUMPFILE)\b/i', $sql)) {
                    throw new RuntimeException('Repair form GET attempted non-read SQL.');
                }
                return $sql;
            }
        }::class);
        try {
            $result = $this->get('/fleet/vehicles/10/damage-repairs/new');
            $result->assertOK();
            $html = $result->getBody();
            $this->assertStringContainsString('Work &amp; Repair | FleetOS', $html);
            $this->assertStringContainsString('id="main-content"', $html);
            foreach (['Undefined', 'Warning:', 'Fatal error', 'Exception:', 'C:\\', '/home/'] as $error) {
                $this->assertStringNotContainsString($error, $html);
            }
            $dom = new DOMDocument();
            @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
            $x = new DOMXPath($dom);
            $targets = $x->query('//input[contains(@name,"[canonical_confirmed]")]');
            $expected = ['empty' => 0, 'body' => 1, 'wheel' => 4, 'mixed' => 5, 'historical' => 0, 'conflict' => 3, 'unknown' => 1, 'generic' => 3][$case];
            $this->assertSame($expected, $targets->length);
            $this->assertSame(in_array($case, ['empty', 'historical'], true), str_contains($html, 'No current known damage recorded.'));
            $this->assertSame($case === 'conflict' ? 2 : 0, $x->query('//input[contains(@name,"[canonical_confirmed]")][@disabled]')->length);
            $this->assertSame(0, $x->query('//input[@name="performed_confirmed"][@disabled]')->length, 'Global mitigation confirmation cannot depend on the final condition row.');
            if (in_array($case, ['wheel', 'mixed'], true)) {
                foreach (['LF', 'RF', 'RR', 'LR'] as $code) {
                    $this->assertStringContainsString($code . ' Wheel — curb rash', $dom->textContent);
                }
            }
            if ($case === 'mixed') {
                $this->assertStringNotContainsString('Synthetic historical', $html);
            }
            if ($case === 'conflict') {
                $this->assertStringContainsString('Review conflicting records', $html);
            }
            if ($case === 'unknown') {
                $this->assertStringContainsString('Synthetic wheel position unknown', $html);
                $this->assertStringNotContainsString('LF Wheel — curb rash', $dom->textContent);
            }
            $this->assertSame($before, Fixture::rows($db));
            foreach ([11, 20, 999] as $vehicle) {
                // Vehicle 11 is owned but its selector must never contain vehicle 10's children.
                try {
                    $other = $this->get('/fleet/vehicles/' . $vehicle . '/damage-repairs/new');
                    if ($vehicle !== 11) {
                        $this->fail('Unowned/absent vehicle must be rejected.');
                    }
                    $other->assertOK();
                    $this->assertStringNotContainsString('[canonical_item_id]', $other->getBody());
                } catch (\CodeIgniter\Exceptions\PageNotFoundException) {
                    $this->assertNotSame(11, $vehicle);
                }
            }
            if ($expected > 0) {
                $child = $db->table('vehicle_damage_items')->orderBy('id')->get()->getRowArray();
                try {
                    $this->get('/fleet/vehicles/11/damage-repairs/new?selected_item_id=' . $child['id']);
                    $this->fail('A raw child ID must not authorize another vehicle condition.');
                } catch (\CodeIgniter\Exceptions\PageNotFoundException) {
                    $this->addToAssertionCount(1);
                }
            }
            $this->assertSame($before, Fixture::rows($db));
        } finally {
            $property->setValue($db, $oldQueryClass);
        }
    }
}
