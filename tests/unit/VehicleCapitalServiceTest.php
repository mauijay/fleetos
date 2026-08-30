<?php

use App\Database\Migrations\AddVehicleCapitalManagement;
use App\Repositories\AuditLogRepository;
use App\Repositories\FleetIntelligenceRepository;
use App\Repositories\LookupRepository;
use App\Repositories\VehicleCapitalRepository;
use App\Services\Fleet\VehicleCapitalService;
use CodeIgniter\Config\Services as CoreServices;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

require_once __DIR__ . '/../../app/Database/Migrations/2026-08-29-000013_AddVehicleCapitalManagement.php';

/** @internal */
#[PreserveGlobalState(false)]
#[RunTestsInSeparateProcesses]
final class VehicleCapitalServiceTest extends CIUnitTestCase
{
    private BaseConnection $connection;
    private VehicleCapitalService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = Database::connect('tests');
        $this->resetSchema();
        $this->createSchema();
        $this->seedData();
        (new AddVehicleCapitalManagement(Database::forge($this->connection)))->up();
        $repository = new VehicleCapitalRepository($this->connection);
        $this->service = new VehicleCapitalService($this->connection, $repository, new AuditLogRepository($this->connection), new LookupRepository($this->connection));
    }

    public function testAcquisitionCreateUpdatePreservesDecimalsAndAuditsActor(): void
    {
        $created = $this->service->saveAcquisition(1, [
            'acquisition_method_lookup_value_id' => $this->lookup('acquisition_method', 'dealer_purchase'),
            'funding_method_lookup_value_id' => $this->lookup('funding_method', 'mixed'),
            'source_name' => 'Honolulu Ford',
            'purchase_order_subtotal' => '48765.43',
            'rebates_incentives' => '1250.25',
            'trade_in_credit' => '2500.00',
            'cash_paid_at_closing' => '5000.00',
            'amount_financed' => '43765.43',
        ], 41);
        $this->assertTrue($created['success']);

        $updated = $this->service->saveAcquisition(1, [
            'acquisition_method_lookup_value_id' => $this->lookup('acquisition_method', 'dealer_purchase'),
            'funding_method_lookup_value_id' => $this->lookup('funding_method', 'mixed'),
            'source_name' => 'Honolulu Ford',
            'purchase_order_subtotal' => '48765.43',
            'rebates_incentives' => '1250.25',
            'trade_in_credit' => '2500.00',
            'cash_paid_at_closing' => '5250.00',
            'amount_financed' => '43765.43',
        ], 42);
        $this->assertTrue($updated['success']);
        $this->assertSame(1, $this->connection->table('vehicle_acquisitions')->countAllResults());
        $record = $this->connection->table('vehicle_acquisitions')->get()->getRowArray();
        $this->assertSame(48765.43, (float) $record['purchase_order_subtotal']);
        $this->assertSame(1250.25, (float) $record['rebates_incentives']);
        $this->assertSame(2500.0, (float) $record['trade_in_credit']);
        $this->assertSame(5250.0, (float) $record['cash_paid_at_closing']);
        $this->assertArrayNotHasKey('amount_financed', $record);
        $audits = $this->connection->table('audit_logs')->where('table_name', 'vehicle_acquisitions')->orderBy('id')->get()->getResultArray();
        $this->assertSame([41, 42], array_map(static fn (array $row): int => (int) $row['actor_user_id'], $audits));
        $this->assertSame(5000.0, (float) json_decode((string) $audits[1]['old_values'], true, 512, JSON_THROW_ON_ERROR)['cash_paid_at_closing']);
        $this->assertSame(5250.0, (float) json_decode((string) $audits[1]['new_values'], true, 512, JSON_THROW_ON_ERROR)['cash_paid_at_closing']);
    }

    public function testCashTransferIncompleteAndFinancedStatesRemainExplicit(): void
    {
        $workspace = $this->service->workspace(1);
        $this->assertSame('not_entered', $workspace['acquisition_state']);
        $this->assertSame('none', $workspace['financing_state']);

        $cash = $this->service->saveAcquisition(1, ['funding_method_lookup_value_id' => $this->lookup('funding_method', 'cash'), 'purchase_order_subtotal' => '0.00'], 7);
        $this->assertTrue($cash['success']);
        $this->assertSame('cash_purchase', $this->service->workspace(1)['acquisition_state']);

        $transfer = $this->service->saveAcquisition(1, ['funding_method_lookup_value_id' => $this->lookup('funding_method', 'transfer'), 'purchase_order_subtotal' => '0.00'], 7);
        $this->assertTrue($transfer['success']);
        $this->assertSame('unknown_incomplete', $this->service->workspace(1)['acquisition_state']);
    }

    public function testOverviewStatesDistinguishFundingFromAggregateLoanStatus(): void
    {
        $this->assertNull($this->service->workspace(1)['acquisition']);
        $this->assertFinancingState('none');

        $this->service->saveAcquisition(1, [
            'funding_method_lookup_value_id' => $this->lookup('funding_method', 'financed'),
            'purchase_order_subtotal' => '48000.00',
        ], 8);
        $lender = $this->service->createLender(['name' => 'Overview Bank'], 8);
        $active = $this->service->saveLoan(1, null, $this->loanData((int) $lender['id']), 8);
        $workspace = $this->service->workspace(1);
        $this->assertSame('Financed', $workspace['acquisition']['funding_method_name']);
        $this->assertFinancingState('active');
        $this->assertCount(1, $workspace['loans']);

        $paidOff = $this->loanData((int) $lender['id']);
        $paidOff['loan_status_lookup_value_id'] = $this->lookup('loan_status', 'paid_off');
        $paidOff['paid_off_on'] = '2026-08-01';
        $this->service->saveLoan(1, null, $paidOff, 8);
        $this->assertFinancingState('active');

        $activeData = $this->loanData((int) $lender['id']);
        $activeData['loan_status_lookup_value_id'] = $this->lookup('loan_status', 'refinanced');
        $this->service->saveLoan(1, (int) $active['id'], $activeData, 8);
        $this->assertFinancingState('refinanced');

        $activeData['loan_status_lookup_value_id'] = $this->lookup('loan_status', 'paid_off');
        $activeData['paid_off_on'] = '2026-08-15';
        $this->service->saveLoan(1, (int) $active['id'], $activeData, 8);
        $this->assertFinancingState('paid_off');
    }

    public function testOverviewUsesDistinctFundingAndLoanStatusLabels(): void
    {
        $view = (string) file_get_contents(__DIR__ . '/../../app/Views/fleet_vehicles/show.php');

        $this->assertStringContainsString('<dt>Funding</dt>', $view);
        $this->assertStringContainsString("\$acquisition['funding_method_name'] ?? 'Not entered'", $view);
        $this->assertStringContainsString('<dt>Loan status</dt>', $view);
        $this->assertStringNotContainsString('<dt>Acquisition</dt>', $view);
        $this->assertStringNotContainsString('<dt>Financing</dt>', $view);
    }

    private function assertFinancingState(string $expected): void
    {
        $this->assertSame($expected, $this->service->workspace(1)['financing_state']);
    }

    public function testLenderAndMultipleLoansSupportRefinanceWhileRejectingCycles(): void
    {
        $lender = $this->service->createLender(['name' => 'Example Bank'], 51);
        $this->assertTrue($lender['success']);
        $first = $this->service->saveLoan(1, null, $this->loanData((int) $lender['id']), 51);
        $this->assertTrue($first['success']);

        $secondData = $this->loanData((int) $lender['id']);
        $secondData['loan_name'] = 'Refinance 2026';
        $secondData['refinanced_from_loan_id'] = $first['id'];
        $second = $this->service->saveLoan(1, null, $secondData, 52);
        $this->assertTrue($second['success']);
        $this->assertSame(2, $this->connection->table('loans')->where('fleet_vehicle_id', 1)->countAllResults());

        $firstData = $this->loanData((int) $lender['id']);
        $firstData['refinanced_from_loan_id'] = $second['id'];
        $cycle = $this->service->saveLoan(1, (int) $first['id'], $firstData, 53);
        $this->assertFalse($cycle['success']);
        $this->assertArrayHasKey('refinanced_from_loan_id', $cycle['errors']);
    }

    public function testAcquisitionPrincipalComesOnlyFromExplicitOriginalLoan(): void
    {
        $financedAcquisition = [
            'funding_method_lookup_value_id' => $this->lookup('funding_method', 'financed'),
            'purchase_order_subtotal' => '50177.49',
            'rebates_incentives' => '2000.00',
            'trade_in_credit' => '0.00',
            'cash_paid_at_closing' => '0.00',
        ];
        $this->assertTrue($this->service->saveAcquisition(1, $financedAcquisition, 55)['success']);

        $lender = $this->service->createLender(['name' => 'Navy Federal Credit Union'], 55);
        $originalData = $this->loanData((int) $lender['id']);
        $originalData['loan_name'] = 'bronco11';
        $originalData['original_principal'] = '48177.49';
        $original = $this->service->saveLoan(1, null, $originalData, 55);
        $this->assertNull($this->service->workspace(1)['acquisition']['acquisition_loan_original_principal']);

        $financedAcquisition['acquisition_loan_id'] = $original['id'];
        $this->assertTrue($this->service->saveAcquisition(1, $financedAcquisition, 56)['success']);

        $refinanceData = $this->loanData((int) $lender['id']);
        $refinanceData['loan_name'] = 'Later refinance';
        $refinanceData['original_principal'] = '49000.00';
        $refinanceData['refinanced_from_loan_id'] = $original['id'];
        $this->assertTrue($this->service->saveLoan(1, null, $refinanceData, 57)['success']);
        $originalData['loan_status_lookup_value_id'] = $this->lookup('loan_status', 'refinanced');
        $this->assertTrue($this->service->saveLoan(1, (int) $original['id'], $originalData, 57)['success']);

        $workspace = $this->service->workspace(1);
        $this->assertSame((int) $original['id'], (int) $workspace['acquisition']['acquisition_loan_id']);
        $this->assertSame(48177.49, (float) $workspace['acquisition']['acquisition_loan_original_principal']);
        $this->assertSame('bronco11', $workspace['acquisition']['acquisition_loan_name']);
    }

    public function testAcquisitionLoanMustBelongToVehicleAndCashNeedsNoLoan(): void
    {
        $lender = $this->service->createLender(['name' => 'Vehicle Scope Bank'], 58);
        $otherLoan = $this->service->saveLoan(2, null, $this->loanData((int) $lender['id']), 58);
        $invalid = $this->service->saveAcquisition(1, [
            'funding_method_lookup_value_id' => $this->lookup('funding_method', 'mixed'),
            'acquisition_loan_id' => $otherLoan['id'],
        ], 58);
        $this->assertFalse($invalid['success']);
        $this->assertArrayHasKey('acquisition_loan_id', $invalid['errors']);

        $mixed = $this->service->saveAcquisition(1, [
            'funding_method_lookup_value_id' => $this->lookup('funding_method', 'mixed'),
            'purchase_order_subtotal' => '12000.00',
        ], 58);
        $this->assertTrue($mixed['success']);
        $this->assertNull($this->service->workspace(1)['acquisition']['acquisition_loan_id']);

        $cash = $this->service->saveAcquisition(1, [
            'funding_method_lookup_value_id' => $this->lookup('funding_method', 'cash'),
            'purchase_order_subtotal' => '12000.00',
        ], 58);
        $this->assertTrue($cash['success']);
        $this->assertNull($this->service->workspace(1)['acquisition']['acquisition_loan_id']);

        $sameVehicleLoan = $this->service->saveLoan(1, null, $this->loanData((int) $lender['id']), 58);
        $cashWithLoan = $this->service->saveAcquisition(1, [
            'funding_method_lookup_value_id' => $this->lookup('funding_method', 'cash'),
            'acquisition_loan_id' => $sameVehicleLoan['id'],
        ], 58);
        $this->assertFalse($cashWithLoan['success']);
        $this->assertSame('A cash acquisition cannot have an acquisition financing agreement.', $cashWithLoan['errors']['acquisition_loan_id']);
    }

    public function testAcquisitionFormUsesApprovedFactsAndPurchaseOrderFlow(): void
    {
        $source = file_get_contents(__DIR__ . '/../../app/Views/fleet_vehicles/show.php');
        $this->assertIsString($source);
        $subtotal = strpos($source, 'Purchase order subtotal<input');
        $rebates = strpos($source, 'Rebates / incentives<input');
        $tradeIn = strpos($source, 'Trade-in credit<input');
        $cash = strpos($source, 'Cash paid at closing<input');
        $financed = strpos($source, '<span>Original amount financed</span>');
        $this->assertNotFalse($subtotal);
        $this->assertTrue($subtotal < $rebates && $rebates < $tradeIn && $tradeIn < $cash && $cash < $financed);
        $this->assertStringNotContainsString('name="amount_financed"', $source);
        $this->assertStringNotContainsString('sales_tax', $source);
        $this->assertStringNotContainsString('dealer_fee', $source);
        $this->assertStringNotContainsString('registration_fee', $source);
        $this->assertGreaterThanOrEqual(2, substr_count($source, '$acquisitionLoanPrincipal'));
    }

    public function testVehicleDetailHeaderUsesScopedResponsiveActions(): void
    {
        $view = file_get_contents(__DIR__ . '/../../app/Views/fleet_vehicles/show.php');
        $css = file_get_contents(__DIR__ . '/../../resources/css/app.css');
        $this->assertIsString($view);
        $this->assertIsString($css);
        $this->assertStringContainsString('class="vehicle-detail-actions"', $view);
        $this->assertStringContainsString('class="secondary-action button-link" href="/fleet/vehicles">Back to vehicles</a>', $view);
        $this->assertStringContainsString('class="primary-action button-link" href="/fleet/vehicles/<?= (int) $vehicle[\'id\'] ?>/edit">Edit vehicle</a>', $view);
        $this->assertStringContainsString('gap: 14px;', $css);
        $this->assertStringContainsString('.vehicle-detail-actions .button-link', $css);
        $this->assertStringContainsString('grid-template-columns: 1fr;', $css);
    }

    public function testLoanValidationProtectsAprDatesDueDayAndVehicleRelationship(): void
    {
        $lender = $this->service->createLender(['name' => 'Validation Bank'], 61);
        $data = $this->loanData((int) $lender['id']);
        $data['interest_rate'] = '100.0000';
        $data['payment_due_day'] = '32';
        $data['first_payment_on'] = '2025-12-01';
        $result = $this->service->saveLoan(1, null, $data, 61);
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('interest_rate', $result['errors']);
        $this->assertArrayHasKey('payment_due_day', $result['errors']);
        $this->assertArrayHasKey('first_payment_on', $result['errors']);
        $this->assertSame(0, $this->connection->table('loans')->countAllResults());
    }

    public function testSnapshotsAppendByDateCorrectSameDateAndSupersedeLegacyBalance(): void
    {
        $lender = $this->service->createLender(['name' => 'Snapshot Bank'], 71);
        $loan = $this->service->saveLoan(1, null, $this->loanData((int) $lender['id']), 71);
        $this->connection->table('loans')->where('id', $loan['id'])->update(['current_balance' => '99999.00']);
        $source = $this->lookup('loan_balance_source', 'lender_statement');
        $first = $this->service->saveSnapshot(1, (int) $loan['id'], ['as_of_date' => '2026-07-31', 'principal_balance' => '42316.24', 'source_method_lookup_value_id' => $source], 71);
        $latest = $this->service->saveSnapshot(1, (int) $loan['id'], ['as_of_date' => '2026-08-29', 'principal_balance' => '41780.10', 'payoff_amount' => '42102.55', 'source_method_lookup_value_id' => $source], 72);
        $corrected = $this->service->saveSnapshot(1, (int) $loan['id'], ['as_of_date' => '2026-08-29', 'principal_balance' => '41770.10', 'payoff_amount' => '42092.55', 'source_method_lookup_value_id' => $source], 73);
        $this->assertTrue($first['success']);
        $this->assertTrue($latest['success']);
        $this->assertTrue($corrected['success']);
        $this->assertSame((int) $latest['id'], (int) $corrected['id']);
        $this->assertSame(2, $this->connection->table('loan_balance_snapshots')->countAllResults());
        $workspace = $this->service->workspace(1);
        $this->assertSame(41770.10, (float) $workspace['loans'][0]['principal_balance']);
        $this->assertSame('2026-08-29', $workspace['loans'][0]['as_of_date']);
        $audit = $this->connection->table('audit_logs')->where('table_name', 'loan_balance_snapshots')->where('actor_user_id', 73)->get()->getRowArray();
        $this->assertNotNull($audit['old_values']);
    }

    public function testExistingSnapshotSupportsNotesOnlyCorrectionWithoutDuplicate(): void
    {
        $lender = $this->service->createLender(['name' => 'Correction Bank'], 74);
        $loan = $this->service->saveLoan(1, null, $this->loanData((int) $lender['id']), 74);
        $source = $this->lookup('loan_balance_source', 'payoff_quote');
        $created = $this->service->saveSnapshot(1, (int) $loan['id'], [
            'as_of_date' => '2026-08-29',
            'principal_balance' => '48676.49',
            'payoff_amount' => '48861.80',
            'source_method_lookup_value_id' => $source,
            'source_reference' => 'Quote 1042',
        ], 74);

        $corrected = $this->service->saveSnapshot(1, (int) $loan['id'], [
            'snapshot_id' => $created['id'],
            'notes' => 'Verified against lender payoff quote.',
        ], 75);

        $this->assertTrue($corrected['success']);
        $this->assertSame((int) $created['id'], (int) $corrected['id']);
        $this->assertSame(1, $this->connection->table('loan_balance_snapshots')->countAllResults());
        $snapshot = $this->connection->table('loan_balance_snapshots')->where('id', $created['id'])->get()->getRowArray();
        $this->assertSame('2026-08-29', $snapshot['as_of_date']);
        $this->assertSame(48676.49, (float) $snapshot['principal_balance']);
        $this->assertSame(48861.80, (float) $snapshot['payoff_amount']);
        $this->assertSame($source, (int) $snapshot['source_method_lookup_value_id']);
        $this->assertSame('Quote 1042', $snapshot['source_reference']);
        $this->assertSame('Verified against lender payoff quote.', $snapshot['notes']);
        $audit = $this->connection->table('audit_logs')->where('table_name', 'loan_balance_snapshots')->where('actor_user_id', 75)->get()->getRowArray();
        $this->assertStringContainsString('48676.49', (string) $audit['old_values']);
        $this->assertStringContainsString('Verified against lender payoff quote.', (string) $audit['new_values']);
        $this->assertSame(75, (int) $audit['actor_user_id']);
    }

    public function testSnapshotCorrectionsMergeIndependentFieldsAndRemainValid(): void
    {
        $lender = $this->service->createLender(['name' => 'Merge Bank'], 77);
        $loan = $this->service->saveLoan(1, null, $this->loanData((int) $lender['id']), 77);
        $statementSource = $this->lookup('loan_balance_source', 'lender_statement');
        $payoffSource = $this->lookup('loan_balance_source', 'payoff_quote');

        $blank = $this->service->saveSnapshot(1, (int) $loan['id'], [
            'as_of_date' => '2026-08-29',
            'source_method_lookup_value_id' => $statementSource,
        ], 77);
        $this->assertFalse($blank['success']);
        $this->assertArrayHasKey('balance', $blank['errors']);

        $created = $this->service->saveSnapshot(1, (int) $loan['id'], [
            'as_of_date' => '2026-08-29',
            'principal_balance' => '48676.49',
            'payoff_amount' => '48861.80',
            'source_method_lookup_value_id' => $statementSource,
        ], 77);
        $snapshotId = (int) $created['id'];
        foreach ([
            ['source_reference' => 'Quote 1042'],
            ['source_method_lookup_value_id' => $payoffSource],
            ['principal_balance' => '48600.00'],
            ['payoff_amount' => '48775.00'],
            ['notes' => 'Multiple-field correction', 'source_reference' => 'Quote 1043'],
        ] as $changes) {
            $result = $this->service->saveSnapshot(1, (int) $loan['id'], ['snapshot_id' => $snapshotId] + $changes, 78);
            $this->assertTrue($result['success']);
            $this->assertSame($snapshotId, (int) $result['id']);
        }

        $invalid = $this->service->saveSnapshot(1, (int) $loan['id'], [
            'snapshot_id' => $snapshotId,
            'principal_balance' => '',
            'payoff_amount' => '',
        ], 79);
        $this->assertFalse($invalid['success']);
        $this->assertArrayHasKey('balance', $invalid['errors']);
        $this->assertSame(1, $this->connection->table('loan_balance_snapshots')->countAllResults());
        $snapshot = $this->connection->table('loan_balance_snapshots')->where('id', $snapshotId)->get()->getRowArray();
        $this->assertSame(48600.0, (float) $snapshot['principal_balance']);
        $this->assertSame(48775.0, (float) $snapshot['payoff_amount']);
        $this->assertSame($payoffSource, (int) $snapshot['source_method_lookup_value_id']);
        $this->assertSame('Quote 1043', $snapshot['source_reference']);
        $this->assertSame('Multiple-field correction', $snapshot['notes']);
        $this->assertSame(5, $this->connection->table('audit_logs')->where('table_name', 'loan_balance_snapshots')->where('record_id', $snapshotId)->where('actor_user_id', 78)->countAllResults());
    }

    public function testSnapshotCorrectionRejectsSnapshotFromAnotherLoan(): void
    {
        $lender = $this->service->createLender(['name' => 'Ownership Bank'], 76);
        $firstLoan = $this->service->saveLoan(1, null, $this->loanData((int) $lender['id']), 76);
        $secondLoan = $this->service->saveLoan(1, null, $this->loanData((int) $lender['id']), 76);
        $snapshot = $this->service->saveSnapshot(1, (int) $firstLoan['id'], [
            'as_of_date' => '2026-08-29',
            'principal_balance' => '48676.49',
            'source_method_lookup_value_id' => $this->lookup('loan_balance_source', 'payoff_quote'),
        ], 76);

        $result = $this->service->saveSnapshot(1, (int) $secondLoan['id'], ['snapshot_id' => $snapshot['id'], 'notes' => 'Wrong loan'], 76);

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('snapshot', $result['errors']);
        $this->assertSame(1, $this->connection->table('loan_balance_snapshots')->countAllResults());
    }

    public function testSnapshotFormSeparatesBlankAddFromPrefilledEdit(): void
    {
        $options = static function (array $rows, mixed $selected = null): string {
            $html = '<option value="">Choose</option>';
            foreach ($rows as $row) {
                $html .= '<option value="' . $row['id'] . '"' . ((int) $selected === (int) $row['id'] ? ' selected' : '') . '>' . $row['name'] . '</option>';
            }

            return $html;
        };
        $data = [
            'vehicle' => ['id' => 1],
            'loan' => ['id' => 2],
            'balance_sources' => [['id' => 3, 'name' => 'Payoff Quote']],
            'options' => $options,
        ];

        $addHtml = CoreServices::renderer()->setData($data)->render('fleet_vehicles/components/snapshot_form');
        $editHtml = CoreServices::renderer()->setData($data + ['snapshot' => [
            'id' => 4,
            'as_of_date' => '2026-08-29',
            'principal_balance' => '48676.49',
            'payoff_amount' => '48861.80',
            'source_method_lookup_value_id' => 3,
            'source_reference' => 'Quote 1042',
            'notes' => 'Existing note',
        ]])->render('fleet_vehicles/components/snapshot_form');

        $this->assertStringNotContainsString('name="snapshot_id"', $addHtml);
        $this->assertStringContainsString('value=""', $addHtml);
        $this->assertStringContainsString('Add snapshot', $addHtml);
        $this->assertStringContainsString('name="snapshot_id" value="4"', $editHtml);
        $this->assertStringContainsString('value="2026-08-29"', $editHtml);
        $this->assertStringContainsString('value="48676.49"', $editHtml);
        $this->assertStringContainsString('value="48861.80"', $editHtml);
        $this->assertStringContainsString('value="3" selected', $editHtml);
        $this->assertStringContainsString('value="Quote 1042"', html_entity_decode($editHtml));
        $this->assertStringContainsString('Existing note', $editHtml);
        $this->assertStringContainsString('Save correction', $editHtml);
    }

    public function testLegacyBalanceIsExposedWithoutManufacturingSnapshot(): void
    {
        $lender = $this->service->createLender(['name' => 'Legacy Bank'], 81);
        $loan = $this->service->saveLoan(1, null, $this->loanData((int) $lender['id']), 81);
        $this->connection->table('loans')->where('id', $loan['id'])->update(['current_balance' => '32100.00']);
        $workspace = $this->service->workspace(1);
        $this->assertSame(32100.0, (float) $workspace['loans'][0]['current_balance']);
        $this->assertNull($workspace['loans'][0]['snapshot_id']);
        $this->assertSame([], $workspace['loans'][0]['snapshots']);
    }

    public function testOutstandingDebtUsesLatestSnapshotAndExcludesPaidOffLoans(): void
    {
        $lender = $this->service->createLender(['name' => 'Debt Bank'], 91);
        $active = $this->service->saveLoan(1, null, $this->loanData((int) $lender['id']), 91);
        $this->connection->table('loans')->where('id', $active['id'])->update(['current_balance' => '40000.00']);
        $this->service->saveSnapshot(1, (int) $active['id'], ['as_of_date' => '2026-08-29', 'principal_balance' => '37500.25', 'source_method_lookup_value_id' => $this->lookup('loan_balance_source', 'lender_statement')], 91);

        $paidData = $this->loanData((int) $lender['id']);
        $paidData['loan_status_lookup_value_id'] = $this->lookup('loan_status', 'paid_off');
        $paidData['paid_off_on'] = '2026-08-01';
        $paid = $this->service->saveLoan(1, null, $paidData, 91);
        $this->connection->table('loans')->where('id', $paid['id'])->update(['current_balance' => '25000.00']);
        $this->connection->table('startup_costs')->insert(['id' => 1, 'fleet_vehicle_id' => 1, 'description' => 'Legacy cost', 'amount' => '5000.00', 'incurred_on' => '2026-01-01']);

        $capital = (new FleetIntelligenceRepository($this->connection))->fleetCapital();
        $this->assertSame(5000.0, $capital['startup_costs']);
        $this->assertSame(37500.25, $capital['outstanding_loan_balance']);
        $this->assertArrayNotHasKey('fleet_value', $capital);
        $this->assertArrayNotHasKey('fleet_equity', $capital);
    }

    public function testLoanFormRendersRefinanceCandidatesWithLoanLabels(): void
    {
        $options = static function (array $rows, mixed $selected = null, string $empty = 'Choose'): string {
            $html = '<option value="">' . htmlspecialchars($empty, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</option>';
            foreach ($rows as $row) {
                $isSelected = (int) $selected === (int) $row['id'] ? ' selected' : '';
                $html .= '<option value="' . (int) $row['id'] . '"' . $isSelected . '>' . htmlspecialchars((string) $row['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</option>';
            }

            return $html;
        };

        $html = CoreServices::renderer()->setData([
            'vehicle' => ['id' => 1],
            'loan' => null,
            'lenders' => [],
            'loan_statuses' => [],
            'loans' => [
                ['id' => 12, 'loan_name' => 'Original note', 'lender_name' => 'Test Lender'],
                ['id' => 13, 'loan_name' => null, 'lender_name' => 'Fallback Bank'],
            ],
            'options' => $options,
        ])->render('fleet_vehicles/components/loan_form');

        $this->assertStringContainsString('Original note', $html);
        $this->assertStringContainsString('Fallback Bank financing', $html);
    }

    /** @return array<string, mixed> */
    private function loanData(int $lenderId): array
    {
        return [
            'lender_id' => $lenderId,
            'loan_status_lookup_value_id' => $this->lookup('loan_status', 'active'),
            'loan_name' => 'Original Loan',
            'account_number_last4' => '1234',
            'original_principal' => '45000.00',
            'interest_rate' => '6.2500',
            'monthly_payment' => '725.55',
            'term_months' => '72',
            'opened_on' => '2026-01-01',
            'first_payment_on' => '2026-02-01',
            'payment_due_day' => '1',
            'matures_on' => '2032-01-01',
        ];
    }

    private function lookup(string $type, string $code): int
    {
        $row = $this->connection->table('lookup_values value')->select('value.id')->join('lookup_types type', 'type.id = value.lookup_type_id')->where('type.code', $type)->where('value.code', $code)->get()->getRowArray();

        return (int) $row['id'];
    }

    private function resetSchema(): void
    {
        foreach (['loan_balance_snapshots', 'vehicle_acquisitions', 'audit_logs', 'vehicle_files', 'vehicle_notes', 'files', 'notes', 'startup_costs', 'turo_transactions_normalized', 'loans', 'lenders', 'fleet_vehicles', 'vehicle_specs', 'vehicle_models', 'vehicle_makes', 'vehicle_statuses', 'companies', 'lookup_values', 'lookup_types'] as $table) {
            $this->connection->query('DROP TABLE IF EXISTS ' . $this->table($table));
        }
    }

    private function createSchema(): void
    {
        $queries = [
            'CREATE TABLE lookup_types (id INTEGER PRIMARY KEY AUTOINCREMENT, code VARCHAR(80) UNIQUE, name VARCHAR(150), created_at DATETIME NULL, updated_at DATETIME NULL)',
            'CREATE TABLE lookup_values (id INTEGER PRIMARY KEY AUTOINCREMENT, lookup_type_id INTEGER, code VARCHAR(80), name VARCHAR(150), sort_order INTEGER DEFAULT 0, is_active INTEGER DEFAULT 1, created_at DATETIME NULL, updated_at DATETIME NULL, UNIQUE(lookup_type_id, code))',
            'CREATE TABLE companies (id INTEGER PRIMARY KEY AUTOINCREMENT, company_type_lookup_value_id INTEGER NULL, name VARCHAR(190), legal_name VARCHAR(190) NULL, slug VARCHAR(120) UNIQUE, is_active INTEGER DEFAULT 1, created_at DATETIME NULL, updated_at DATETIME NULL, deleted_at DATETIME NULL)',
            'CREATE TABLE vehicle_statuses (id INTEGER PRIMARY KEY, name VARCHAR(120))',
            'CREATE TABLE vehicle_makes (id INTEGER PRIMARY KEY, name VARCHAR(120))',
            'CREATE TABLE vehicle_models (id INTEGER PRIMARY KEY, vehicle_make_id INTEGER, name VARCHAR(120))',
            'CREATE TABLE vehicle_specs (id INTEGER PRIMARY KEY, vehicle_model_id INTEGER, model_year INTEGER)',
            'CREATE TABLE fleet_vehicles (id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER, vehicle_spec_id INTEGER, vehicle_status_id INTEGER, fleet_code VARCHAR(80), display_name VARCHAR(150), purchase_date DATE NULL, license_plate VARCHAR(32) NULL, deleted_at DATETIME NULL)',
            'CREATE TABLE lenders (id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER UNIQUE, created_at DATETIME NULL, updated_at DATETIME NULL, deleted_at DATETIME NULL)',
            'CREATE TABLE loans (id INTEGER PRIMARY KEY AUTOINCREMENT, fleet_vehicle_id INTEGER, lender_id INTEGER, loan_status_lookup_value_id INTEGER NULL, account_number_last4 VARCHAR(4) NULL, original_principal DECIMAL(12,2) NULL, current_balance DECIMAL(12,2) NULL, interest_rate DECIMAL(6,4) NULL, monthly_payment DECIMAL(10,2) NULL, term_months INTEGER NULL, opened_on DATE NULL, matures_on DATE NULL, paid_off_on DATE NULL, created_at DATETIME NULL, updated_at DATETIME NULL, deleted_at DATETIME NULL)',
            'CREATE TABLE startup_costs (id INTEGER PRIMARY KEY, fleet_vehicle_id INTEGER, cost_type_lookup_value_id INTEGER NULL, description VARCHAR(190), amount DECIMAL(10,2), incurred_on DATE, deleted_at DATETIME NULL)',
            'CREATE TABLE turo_transactions_normalized (id INTEGER PRIMARY KEY, fleet_vehicle_id INTEGER NULL, event_class VARCHAR(40), amount DECIMAL(10,2))',
            'CREATE TABLE notes (id INTEGER PRIMARY KEY, body TEXT, noted_at DATETIME NULL, deleted_at DATETIME NULL)',
            'CREATE TABLE files (id INTEGER PRIMARY KEY, original_filename VARCHAR(190) NULL, document_date DATE NULL, deleted_at DATETIME NULL)',
            'CREATE TABLE vehicle_notes (id INTEGER PRIMARY KEY, fleet_vehicle_id INTEGER, note_id INTEGER, note_type_lookup_value_id INTEGER)',
            'CREATE TABLE vehicle_files (id INTEGER PRIMARY KEY, fleet_vehicle_id INTEGER, file_id INTEGER, file_type_lookup_value_id INTEGER)',
            'CREATE TABLE audit_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, actor_user_id INTEGER NULL, action_lookup_value_id INTEGER NULL, table_name VARCHAR(120), record_id INTEGER, old_values TEXT NULL, new_values TEXT NULL, created_at DATETIME NULL)',
        ];
        foreach ($queries as $query) {
            $this->connection->query(str_replace('CREATE TABLE ', 'CREATE TABLE ' . $this->connection->getPrefix(), $query));
        }
    }

    private function seedData(): void
    {
        foreach (['file_type', 'company_type', 'loan_status', 'audit_action'] as $type) {
            $this->connection->table('lookup_types')->insert(['code' => $type, 'name' => ucwords(str_replace('_', ' ', $type))]);
        }
        foreach ([['company_type', 'fleet_owner', 'Fleet Owner'], ['company_type', 'lender', 'Lender'], ['loan_status', 'active', 'Active'], ['loan_status', 'paid_off', 'Paid Off'], ['loan_status', 'refinanced', 'Refinanced'], ['audit_action', 'created', 'Created'], ['audit_action', 'updated', 'Updated']] as [$type, $code, $name]) {
            $typeId = (int) $this->connection->table('lookup_types')->where('code', $type)->get()->getRowArray()['id'];
            $this->connection->table('lookup_values')->insert(['lookup_type_id' => $typeId, 'code' => $code, 'name' => $name]);
        }
        $this->connection->table('companies')->insert(['id' => 1, 'company_type_lookup_value_id' => $this->lookup('company_type', 'fleet_owner'), 'name' => 'Fleet Owner', 'slug' => 'fleet-owner']);
        $this->connection->table('vehicle_statuses')->insert(['id' => 1, 'name' => 'Active']);
        $this->connection->table('vehicle_makes')->insert(['id' => 1, 'name' => 'Ford']);
        $this->connection->table('vehicle_models')->insert(['id' => 1, 'vehicle_make_id' => 1, 'name' => 'Bronco']);
        $this->connection->table('vehicle_specs')->insert(['id' => 1, 'vehicle_model_id' => 1, 'model_year' => 2026]);
        $this->connection->table('fleet_vehicles')->insert(['id' => 1, 'company_id' => 1, 'vehicle_spec_id' => 1, 'vehicle_status_id' => 1, 'fleet_code' => 'Bronco11-087', 'display_name' => 'Bronco']);
        $this->connection->table('fleet_vehicles')->insert(['id' => 2, 'company_id' => 1, 'vehicle_spec_id' => 1, 'vehicle_status_id' => 1, 'fleet_code' => 'Other-001', 'display_name' => 'Other vehicle']);
    }

    private function table(string $table): string
    {
        return $this->connection->getPrefix() . $table;
    }
}
