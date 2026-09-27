<?php

use App\Repositories\LookupRepository;
use App\Repositories\SuperchargerReconciliationRepository;
use App\Services\Fleet\CurrentVehicleCustodyService;
use App\Services\Fleet\SuperchargerReconciliationService;
use App\Services\Tesla\TeslaChargingImportService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

/** @internal */
final class TeslaChargingImportServiceTest extends CIUnitTestCase
{
    private BaseConnection $connection;
    private SuperchargerReconciliationRepository $repository;
    private TeslaChargingCustodyStub $custody;
    private TeslaChargingImportService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = Database::connect('tests', false);
        $this->resetSchema();
        $this->createSchema();
        $this->seed();
        $this->repository = new SuperchargerReconciliationRepository($this->connection);
        $this->custody = new TeslaChargingCustodyStub();
        $lookups = $this->getMockBuilder(LookupRepository::class)->disableOriginalConstructor()->onlyMethods(['valueId'])->getMock();
        $lookups->method('valueId')->willReturn(99);
        $this->service = new TeslaChargingImportService($this->repository, $this->custody, $lookups);
    }

    public function testGuestHeldLinesRollUpToOneExpenseAndExactReplayIsIdempotent(): void
    {
        $this->custody->resolved = $this->custody('guest', 100, 501, 'actual_handoff');
        $path = $this->csv([
            $this->row('CHARGING PAYMENT', '6.50'),
            $this->row('CONGESTION PAYMENT', '0.75'),
            $this->row('CONGESTION : NO_CHARGE', '0.00'),
        ]);

        $first = $this->service->import($path, 1, 7, 'tesla.csv');
        $replay = $this->service->import($path, 1, 7, 'tesla.csv');
        @unlink($path);

        $session = $this->connection->table('charging_sessions')->get()->getRowArray();
        $this->assertSame(3, $first['imported']);
        $this->assertFalse($first['replayed']);
        $this->assertTrue($replay['replayed']);
        $this->assertSame(1, $this->connection->table('charging_sessions')->countAllResults());
        $this->assertSame(3, $this->connection->table('tesla_charging_line_items')->countAllResults());
        $this->assertSame(1, $this->connection->table('tesla_charging_import_batches')->countAllResults());
        $this->assertSame('7.25', number_format((float) $session['cost_amount'], 2, '.', ''));
        $this->assertSame('2026-09-25T13:15:00-10:00', $session['source_started_at']);
        $this->assertNull($session['ended_at']);
        $this->assertSame(100, (int) $session['turo_trip_normalized_id']);
        $this->assertSame(1, $this->connection->table('supercharger_reimbursement_cases')->countAllResults());
    }

    public function testOverlappingSnapshotAddsOnlyNewSourceLine(): void
    {
        $this->custody->resolved = $this->custody('guest', 100, 501, 'actual_handoff');
        $firstPath = $this->csv([$this->row('CHARGING PAYMENT', '6.50')]);
        $this->service->import($firstPath, 1, 7, 'first.csv');
        @unlink($firstPath);
        $nextPath = $this->csv([
            $this->row('CHARGING PAYMENT', '6.50'),
            $this->row('CONGESTION PAYMENT', '0.75'),
        ]);

        $result = $this->service->import($nextPath, 1, 7, 'overlap.csv');
        @unlink($nextPath);

        $this->assertSame(1, $result['imported']);
        $this->assertSame(1, $result['duplicate']);
        $this->assertSame(2, $this->connection->table('tesla_charging_line_items')->countAllResults());
        $this->assertSame('7.25', number_format((float) $this->connection->table('charging_sessions')->get()->getRow('cost_amount'), 2, '.', ''));
    }

    public function testUnknownVinAndOffsetlessTimestampRemainRawWithoutAttachment(): void
    {
        $unknownPath = $this->csv([$this->row('CHARGING PAYMENT', '4.00', vin: 'FAKEVIN-NOT-MAPPED')]);
        $unknown = $this->service->import($unknownPath, 1, 7, 'unknown.csv');
        @unlink($unknownPath);
        $invalidPath = $this->csv([$this->row('CHARGING PAYMENT', '4.00', timestamp: '2026-09-25 13:15:00')]);
        $invalid = $this->service->import($invalidPath, 1, 7, 'invalid.csv');
        @unlink($invalidPath);

        $this->assertSame(1, $unknown['review']);
        $this->assertSame(1, $invalid['rejected']);
        $this->assertSame(2, $this->connection->table('tesla_charging_import_rows')->countAllResults());
        $this->assertSame(0, $this->connection->table('charging_sessions')->countAllResults());
        $this->assertSame(['vin_unmapped', 'timestamp_without_offset'], array_column($this->connection->table('tesla_charging_import_rows')->orderBy('id')->get()->getResultArray(), 'issue_code'));
    }

    public function testScheduleOnlyIsReviewWhileOperatorCustodyIsHostExpense(): void
    {
        $this->custody->resolved = $this->custody('unknown');
        $reviewPath = $this->csv([$this->row('CHARGING PAYMENT', '4.00')]);
        $review = $this->service->import($reviewPath, 1, 7, 'schedule.csv');
        @unlink($reviewPath);
        $this->custody->resolved = $this->custody('operator', null, 502, 'vehicle_recovered');
        $hostPath = $this->csv([$this->row('CHARGING PAYMENT', '2.00', timestamp: '2026-09-25T20:15:00-10:00', invoice: 'INV-2')]);
        $host = $this->service->import($hostPath, 1, 7, 'host.csv');
        @unlink($hostPath);

        $sessions = $this->connection->table('charging_sessions')->orderBy('id')->get()->getResultArray();
        $workspace = (new SuperchargerReconciliationService($this->repository))->workspace(1, 'all');
        $this->assertSame(1, $review['review']);
        $this->assertSame('review', $sessions[0]['custody_classification']);
        $this->assertSame(100, (int) $sessions[0]['candidate_turo_trip_normalized_id']);
        $this->assertNull($sessions[0]['turo_trip_normalized_id']);
        $this->assertSame(1, $host['imported']);
        $this->assertSame('operator', $sessions[1]['custody_classification']);
        $this->assertEqualsCanonicalizing(['review', 'host_expense'], array_column($workspace['sessions'], 'financial_status'));
        $this->assertSame(0, $this->connection->table('supercharger_reimbursement_cases')->countAllResults());
    }

    public function testFinancialStatesUseOnTripAmountAndIgnorePostTripAmount(): void
    {
        $service = new SuperchargerReconciliationService($this->repository);

        $this->assertSame('no_cost', $service->financialStatus(0, 0));
        $this->assertSame('eligible_unreconciled', $service->financialStatus(725, 0));
        $this->assertSame('partially_reconciled', $service->financialStatus(725, 500));
        $this->assertSame('reconciled', $service->financialStatus(725, 725));
        $this->assertSame('over_reconciled_review', $service->financialStatus(725, 900));
    }

    public function testZeroCostGuestSessionIsPreservedWithoutReimbursementNeed(): void
    {
        $this->custody->resolved = $this->custody('guest', 100, 501, 'actual_handoff');
        $path = $this->csv([$this->row('CHARGING : NO_CHARGE', '0.00')]);
        $this->service->import($path, 1, 7, 'zero.csv');
        @unlink($path);

        $before = $this->connection->table('supercharger_reimbursement_cases')->countAllResults()
            + $this->connection->table('charging_sessions')->countAllResults()
            + $this->connection->table('tesla_charging_line_items')->countAllResults();
        $workspace = (new SuperchargerReconciliationService($this->repository))->workspace(1, 'all');
        $after = $this->connection->table('supercharger_reimbursement_cases')->countAllResults()
            + $this->connection->table('charging_sessions')->countAllResults()
            + $this->connection->table('tesla_charging_line_items')->countAllResults();

        $this->assertSame('no_cost', $workspace['cases'][0]['financial_status']);
        $this->assertSame(9900, (int) round((float) $workspace['cases'][0]['post_trip_ev_charging_amount'] * 100));
        $this->assertSame($before, $after, 'Reading the workspace must not mutate reconciliation state.');
    }

    public function testAllManualWorkflowStatesAreAuditedWithoutChangingDerivedMoneyState(): void
    {
        $this->custody->resolved = $this->custody('guest', 100, 501, 'actual_handoff');
        $path = $this->csv([$this->row('CHARGING PAYMENT', '6.50')]);
        $this->service->import($path, 1, 7, 'submitted.csv');
        @unlink($path);
        $caseId = (int) $this->connection->table('supercharger_reimbursement_cases')->get()->getRow('id');
        $workflow = new SuperchargerReconciliationService($this->repository);

        foreach (['not_submitted', 'submitted', 'no_invoice_needed', 'waived'] as $index => $state) {
            $workflow->changeWorkflow(1, $caseId, $state, 'Synthetic workflow ' . $state . '.', 'SYNTH-REF', 7);
            $case = $workflow->workspace(1, 'all')['cases'][0];
            $this->assertSame($state, $case['workflow_state_code']);
            $this->assertSame('eligible_unreconciled', $case['financial_status']);
            $this->assertSame($index + 1, $this->connection->table('operational_fact_audits')->countAllResults());
            if ($state === 'submitted') {
                $this->assertSame(7, (int) $case['workflow_changed_by']);
                $this->assertNotEmpty($case['workflow_changed_at']);
            }
        }
    }

    public function testWrongCompanyVinAndInvalidAmountsAreNeverAttached(): void
    {
        $wrongCompany = $this->csv([$this->row('CHARGING PAYMENT', '3.00', vin: 'OTHERCOMPANYVIN001')]);
        $first = $this->service->import($wrongCompany, 1, 7, 'wrong-company.csv');
        @unlink($wrongCompany);
        $negative = $this->csv([$this->row('CHARGING PAYMENT', '-1.00')]);
        $second = $this->service->import($negative, 1, 7, 'negative.csv');
        @unlink($negative);
        $malformed = $this->csv([$this->row('CHARGING PAYMENT', 'not-money')]);
        $third = $this->service->import($malformed, 1, 7, 'malformed.csv');
        @unlink($malformed);

        $this->assertSame(1, $first['review']);
        $this->assertSame(1, $second['rejected']);
        $this->assertSame(1, $third['rejected']);
        $this->assertSame(['vin_company_mismatch', 'invalid_total_inc_vat', 'invalid_total_inc_vat'], array_column($this->connection->table('tesla_charging_import_rows')->orderBy('id')->get()->getResultArray(), 'issue_code'));
        $this->assertSame(0, $this->connection->table('charging_sessions')->countAllResults());
    }

    public function testUnsafeInvoiceUrlIsPreservedOnlyInRawSourceData(): void
    {
        $this->custody->resolved = $this->custody('guest', 100, 501, 'actual_handoff');
        $path = $this->csv([$this->row('CHARGING PAYMENT', '3.00', invoiceUrl: 'javascript:alert(1)')]);

        $result = $this->service->import($path, 1, 7, 'unsafe-url.csv');
        @unlink($path);

        $rawPayload = json_decode((string) $this->connection->table('tesla_charging_import_rows')->get()->getRow('raw_payload'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(1, $result['imported']);
        $this->assertSame('javascript:alert(1)', $rawPayload['invoice']);
        $this->assertNull($this->connection->table('charging_sessions')->get()->getRow('source_invoice_url'));
        $this->assertNull($this->connection->table('tesla_charging_line_items')->get()->getRow('invoice_url'));
    }

    public function testConfirmedBetweenTripChargeIsHostExpense(): void
    {
        $this->connection->table('turo_trips_normalized')->insert([
            'id' => 101, 'fleet_vehicle_id' => 10, 'trip_status_lookup_value_id' => 10,
            'turo_trip_id' => 'SYNTH-TRIP-101', 'turo_reservation_id' => 'SYNTH-RES-101', 'guest_name' => 'Synthetic Next Guest',
            'starts_at' => '2026-09-25 21:00:00', 'ends_at' => '2026-09-26 08:00:00', 'on_trip_ev_charging_amount' => '0.00', 'post_trip_ev_charging_amount' => '0.00',
        ]);
        $this->custody->resolved = $this->custody('operator', null, 502, 'vehicle_recovered');
        $path = $this->csv([$this->row('CHARGING PAYMENT', '8.00', timestamp: '2026-09-25T20:00:00-10:00', invoice: 'BETWEEN-1')]);

        $result = $this->service->import($path, 1, 7, 'between.csv');
        @unlink($path);

        $session = $this->connection->table('charging_sessions')->get()->getRowArray();
        $this->assertSame(1, $result['imported']);
        $this->assertSame('between_trips', $session['custody_classification']);
        $this->assertSame('authoritative_operator_between_trip_gap', $session['custody_basis_code']);
        $this->assertSame(502, (int) $session['custody_basis_event_id']);
        $this->assertNull($session['turo_trip_normalized_id']);
        $this->assertSame(0, $this->connection->table('supercharger_reimbursement_cases')->countAllResults());
    }

    public function testScheduleOnlyBetweenTripGapRemainsReviewOnly(): void
    {
        $this->connection->table('turo_trips_normalized')->insert([
            'id' => 101, 'fleet_vehicle_id' => 10, 'trip_status_lookup_value_id' => 10,
            'turo_trip_id' => 'SYNTH-TRIP-101', 'turo_reservation_id' => 'SYNTH-RES-101', 'guest_name' => 'Synthetic Next Guest',
            'starts_at' => '2026-09-25 21:00:00', 'ends_at' => '2026-09-26 08:00:00', 'on_trip_ev_charging_amount' => '0.00', 'post_trip_ev_charging_amount' => '0.00',
        ]);
        $this->custody->resolved = $this->custody('unknown');
        $path = $this->csv([$this->row('CHARGING PAYMENT', '8.00', timestamp: '2026-09-25T20:00:00-10:00', invoice: 'SCHEDULE-GAP')]);

        $result = $this->service->import($path, 1, 7, 'schedule-gap.csv');
        @unlink($path);

        $session = $this->connection->table('charging_sessions')->get()->getRowArray();
        $this->assertSame(1, $result['review']);
        $this->assertSame('review', $session['custody_classification']);
        $this->assertSame('schedule_only_between_trip_gap', $session['custody_basis_code']);
        $this->assertNull($session['turo_trip_normalized_id']);
        $this->assertSame(0, $this->connection->table('supercharger_reimbursement_cases')->countAllResults());
    }

    public function testSpace10PatternUsesExactEligibleTotalWithoutVehicleSpecificRules(): void
    {
        $this->custody->resolved = $this->custody('guest', 100, 501, 'actual_handoff');
        $path = $this->csv([
            $this->row('CHARGING PAYMENT', '11.25', invoice: 'SPACE10-SYNTH'),
            $this->row('IDLE FEE : PAYMENT', '2.75', invoice: 'SPACE10-SYNTH'),
        ]);
        $this->service->import($path, 1, 7, 'space10-synthetic.csv');
        @unlink($path);
        $reconciliation = new SuperchargerReconciliationService($this->repository);

        $unreconciled = $reconciliation->workspace(1, 'all')['cases'][0];
        $this->connection->table('turo_trips_normalized')->where('id', 100)->update(['on_trip_ev_charging_amount' => '14.00']);
        $reconciled = $reconciliation->workspace(1, 'all')['cases'][0];

        $this->assertSame(1400, $unreconciled['eligible_cost_cents']);
        $this->assertSame(1400, $unreconciled['outstanding_cents']);
        $this->assertSame('eligible_unreconciled', $unreconciled['financial_status']);
        $this->assertSame('reconciled', $reconciled['financial_status']);
        $this->assertSame(0, $reconciled['outstanding_cents']);
    }

    public function testFreeCongestionCreatesHistoryButNoAmountDue(): void
    {
        $this->custody->resolved = $this->custody('guest', 100, 501, 'actual_handoff');
        $path = $this->csv([$this->row('CONGESTION : NO_CHARGE', '0.00', invoice: 'FREE-CONGESTION')]);
        $this->service->import($path, 1, 7, 'free-congestion.csv');
        @unlink($path);

        $workspace = (new SuperchargerReconciliationService($this->repository))->workspace(1, 'all');
        $this->assertSame('CONGESTION : NO_CHARGE', $workspace['cases'][0]['line_items'][0]['fee_description']);
        $this->assertSame(0, $workspace['cases'][0]['eligible_cost_cents']);
        $this->assertSame('no_cost', $workspace['cases'][0]['financial_status']);
    }

    public function testCanceledCustodyTripIsPreservedForReviewWithoutAttachment(): void
    {
        $this->connection->table('lookup_values')->insert(['id' => 11, 'code' => 'canceled_zero_payout']);
        $this->connection->table('turo_trips_normalized')->where('id', 100)->update(['trip_status_lookup_value_id' => 11]);
        $this->custody->resolved = $this->custody('guest', 100, 501, 'actual_handoff');
        $path = $this->csv([$this->row('CHARGING PAYMENT', '5.00', invoice: 'CANCELED-TRIP')]);

        $result = $this->service->import($path, 1, 7, 'canceled-trip.csv');
        @unlink($path);

        $session = $this->connection->table('charging_sessions')->get()->getRowArray();
        $this->assertSame(1, $result['review']);
        $this->assertSame('review', $session['custody_classification']);
        $this->assertSame('custody_trip_ineligible', $session['custody_basis_code']);
        $this->assertNull($session['turo_trip_normalized_id']);
        $this->assertSame(0, $this->connection->table('supercharger_reimbursement_cases')->countAllResults());
    }

    public function testUnknownFeeDescriptionIsCandidateOnlyAndNeverAutoAttached(): void
    {
        $this->custody->resolved = $this->custody('guest', 100, 501, 'actual_handoff');
        $path = $this->csv([$this->row('UNRECOGNIZED TESLA FEE', '5.00', invoice: 'UNKNOWN-FEE')]);

        $result = $this->service->import($path, 1, 7, 'unknown-fee.csv');
        @unlink($path);

        $session = $this->connection->table('charging_sessions')->get()->getRowArray();
        $this->assertSame(1, $result['review']);
        $this->assertSame('unknown_fee_description', $session['custody_basis_code']);
        $this->assertNull($session['turo_trip_normalized_id']);
        $this->assertSame(100, (int) $session['candidate_turo_trip_normalized_id']);
        $this->assertSame(0, $this->connection->table('supercharger_reimbursement_cases')->countAllResults());
    }

    public function testAmbiguousCompanyVinIsPreservedWithoutVehicleAttachment(): void
    {
        $this->connection->table('fleet_vehicles')->insert([
            'id' => 11, 'company_id' => 1, 'fleet_code' => 'SYNTH-11', 'display_name' => 'Duplicate VIN', 'vin' => 'SYNTHETICVIN00001',
        ]);
        $path = $this->csv([$this->row('CHARGING PAYMENT', '5.00', invoice: 'AMBIGUOUS-VIN')]);

        $result = $this->service->import($path, 1, 7, 'ambiguous-vin.csv');
        @unlink($path);

        $this->assertSame(1, $result['review']);
        $this->assertSame('vin_ambiguous', $this->connection->table('tesla_charging_import_rows')->get()->getRow('issue_code'));
        $this->assertSame(0, $this->connection->table('charging_sessions')->countAllResults());
    }

    public function testUnknownLineDowngradesSharedSessionRatherThanInflatingEligibleCost(): void
    {
        $this->custody->resolved = $this->custody('guest', 100, 501, 'actual_handoff');
        $path = $this->csv([
            $this->row('CHARGING PAYMENT', '5.00', invoice: 'MIXED-UNKNOWN'),
            $this->row('UNRECOGNIZED TESLA FEE', '2.00', invoice: 'MIXED-UNKNOWN'),
        ]);

        $result = $this->service->import($path, 1, 7, 'mixed-unknown.csv');
        @unlink($path);

        $session = $this->connection->table('charging_sessions')->get()->getRowArray();
        $workspace = (new SuperchargerReconciliationService($this->repository))->workspace(1, 'all');
        $this->assertSame(1, $result['imported']);
        $this->assertSame(1, $result['review']);
        $this->assertSame('review', $session['custody_classification']);
        $this->assertNull($session['turo_trip_normalized_id']);
        $this->assertSame(100, (int) $session['candidate_turo_trip_normalized_id']);
        $this->assertSame('7.00', number_format((float) $session['cost_amount'], 2, '.', ''));
        $this->assertSame('no_cost', $workspace['cases'][0]['financial_status']);
        $this->assertSame('review', $workspace['sessions'][0]['financial_status']);
    }

    public function testFiltersReconcileToVisibleCasesSessionsAndIssues(): void
    {
        $this->custody->resolved = $this->custody('guest', 100, 501, 'actual_handoff');
        $guestPath = $this->csv([$this->row('CHARGING PAYMENT', '6.50', invoice: 'FILTER-GUEST')]);
        $this->service->import($guestPath, 1, 7, 'filter-guest.csv');
        @unlink($guestPath);

        $this->custody->resolved = $this->custody('operator', null, 502, 'vehicle_recovered');
        $hostPath = $this->csv([$this->row('CHARGING PAYMENT', '2.00', timestamp: '2026-09-25T20:15:00-10:00', invoice: 'FILTER-HOST')]);
        $this->service->import($hostPath, 1, 7, 'filter-host.csv');
        @unlink($hostPath);

        $reviewPath = $this->csv([$this->row('CHARGING PAYMENT', '3.00', vin: 'UNKNOWN-FILTER-VIN', invoice: 'FILTER-REVIEW')]);
        $this->service->import($reviewPath, 1, 7, 'filter-review.csv');
        @unlink($reviewPath);

        $reconciliation = new SuperchargerReconciliationService($this->repository);
        foreach (['attention' => 2, 'host' => 1, 'review' => 1, 'all' => 3] as $filter => $expected) {
            $workspace = $reconciliation->workspace(1, $filter);
            $visible = count($workspace['cases']) + count($workspace['sessions']) + count($workspace['import_issues']);
            $this->assertSame($expected, $workspace['summary'][$filter]);
            $this->assertSame($expected, $visible);
        }
        $this->assertSame(0, $reconciliation->workspace(1, 'reconciled')['summary']['reconciled']);

        $this->connection->table('turo_trips_normalized')->where('id', 100)->update(['on_trip_ev_charging_amount' => '6.50']);
        $reconciled = $reconciliation->workspace(1, 'reconciled');
        $this->assertSame(1, $reconciled['summary']['reconciled']);
        $this->assertSame(1, count($reconciled['cases']));
    }

    /** @return array<string, mixed> */
    private function row(string $description, string $amount, string $vin = 'SYNTHETICVIN00001', string $timestamp = '2026-09-25T13:15:00-10:00', string $invoice = 'INV-1', ?string $invoiceUrl = null): array
    {
        return [
            'ChargeStartDateTime' => $timestamp,
            'Name' => 'Synthetic Tesla',
            'Vin' => $vin,
            'Model' => 'Model Y',
            'Country' => 'US',
            'SiteLocationName' => 'Synthetic Supercharger',
            'Description' => $description,
            'QuantityBase' => '1',
            'QuantityTier1' => '', 'QuantityTier2' => '', 'QuantityTier3' => '', 'QuantityTier4' => '',
            'InvoiceNumber' => $invoice,
            'UnitCostBase' => $amount,
            'UnitCostTier1' => '', 'UnitCostTier2' => '', 'UnitCostTier3' => '', 'UnitCostTier4' => '',
            'VAT' => '0.00',
            'Total Exc. VAT' => $amount,
            'Total Inc. VAT' => $amount,
            'Status' => 'PAID',
            'Invoice' => $invoiceUrl ?? 'https://example.invalid/invoice/' . $invoice,
        ];
    }

    /** @param list<array<string, mixed>> $rows */
    private function csv(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'tesla-import-');
        if ($path === false) {
            throw new RuntimeException('Unable to create temporary Tesla fixture.');
        }
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new RuntimeException('Unable to open temporary Tesla fixture.');
        }
        fputcsv($handle, array_keys($rows[0]), ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($handle, array_values($row), ',', '"', '');
        }
        fclose($handle);

        return $path;
    }

    /** @return array<string, mixed> */
    private function custody(string $custody, ?int $tripId = null, ?int $eventId = null, ?string $eventCode = null): array
    {
        return ['custody' => $custody, 'active_trip_id' => $tripId, 'basis_trip_id' => $tripId, 'basis_event_id' => $eventId, 'basis_event_code' => $eventCode, 'occurred_at' => null, 'basis_event' => null];
    }

    private function resetSchema(): void
    {
        $this->connection->query('PRAGMA foreign_keys = OFF');
        foreach (['operational_fact_audits', 'supercharger_reimbursement_cases', 'tesla_charging_line_items', 'tesla_charging_import_rows', 'tesla_charging_import_batches', 'charging_sessions', 'trip_movement_events', 'turo_trips_normalized', 'lookup_values', 'fleet_vehicles', 'companies'] as $table) {
            $this->connection->query('DROP TABLE IF EXISTS ' . $this->table($table));
        }
        $this->connection->query('PRAGMA foreign_keys = ON');
    }

    private function createSchema(): void
    {
        $this->connection->query('CREATE TABLE ' . $this->table('companies') . ' (id INTEGER PRIMARY KEY, name VARCHAR(80))');
        $this->connection->query('CREATE TABLE ' . $this->table('fleet_vehicles') . ' (id INTEGER PRIMARY KEY, company_id INTEGER, fleet_code VARCHAR(80), display_name VARCHAR(150), vin VARCHAR(32), deleted_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('lookup_values') . ' (id INTEGER PRIMARY KEY, code VARCHAR(80))');
        $this->connection->query('CREATE TABLE ' . $this->table('turo_trips_normalized') . ' (id INTEGER PRIMARY KEY, fleet_vehicle_id INTEGER, trip_status_lookup_value_id INTEGER, turo_trip_id VARCHAR(80), turo_reservation_id VARCHAR(80), guest_name VARCHAR(190), starts_at DATETIME, ends_at DATETIME, canceled_at DATETIME NULL, on_trip_ev_charging_amount DECIMAL(10,2), post_trip_ev_charging_amount DECIMAL(10,2), deleted_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('trip_movement_events') . ' (id INTEGER PRIMARY KEY)');
        $this->connection->query('CREATE TABLE ' . $this->table('charging_sessions') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, fleet_vehicle_id INTEGER, charging_provider_lookup_value_id INTEGER NULL, charging_location VARCHAR(190), started_at DATETIME NULL, ended_at DATETIME NULL, kwh DECIMAL(8,3) NULL, cost_amount DECIMAL(10,2), odometer_miles INTEGER NULL, turo_trip_normalized_id INTEGER NULL, source_type VARCHAR(40), source_session_fingerprint CHAR(64), source_invoice_number VARCHAR(120), source_vin VARCHAR(32), source_started_at VARCHAR(64), source_invoice_url VARCHAR(1000), custody_classification VARCHAR(40), custody_basis_code VARCHAR(80), custody_basis_event_id INTEGER NULL, candidate_turo_trip_normalized_id INTEGER NULL, created_at DATETIME, updated_at DATETIME, deleted_at DATETIME NULL)');
        $this->connection->query('CREATE TABLE ' . $this->table('tesla_charging_import_batches') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER, source_filename VARCHAR(255), source_hash CHAR(64), status_code VARCHAR(30), row_count INTEGER DEFAULT 0, imported_count INTEGER DEFAULT 0, duplicate_count INTEGER DEFAULT 0, review_count INTEGER DEFAULT 0, rejected_count INTEGER DEFAULT 0, error_message TEXT NULL, imported_by INTEGER, created_at DATETIME, completed_at DATETIME NULL, UNIQUE(company_id, source_hash))');
        $this->connection->query('CREATE TABLE ' . $this->table('tesla_charging_import_rows') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER, tesla_charging_import_batch_id INTEGER, row_number INTEGER, row_hash CHAR(64), raw_payload TEXT, status_code VARCHAR(30), issue_code VARCHAR(80) NULL, issue_detail TEXT NULL, created_at DATETIME)');
        $this->connection->query('CREATE TABLE ' . $this->table('tesla_charging_line_items') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER, charging_session_id INTEGER, tesla_charging_import_row_id INTEGER, source_line_fingerprint CHAR(64), invoice_number VARCHAR(120), vin VARCHAR(32), source_started_at VARCHAR(64), started_at DATETIME, site_location_name VARCHAR(255), fee_description VARCHAR(255), quantity_source TEXT NULL, unit_cost_source TEXT NULL, total_inc_vat_amount DECIMAL(10,2), invoice_url VARCHAR(1000), created_at DATETIME, UNIQUE(company_id, source_line_fingerprint))');
        $this->connection->query('CREATE TABLE ' . $this->table('supercharger_reimbursement_cases') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER, fleet_vehicle_id INTEGER, turo_trip_normalized_id INTEGER, workflow_state_code VARCHAR(30), workflow_note TEXT NULL, invoice_reference VARCHAR(190) NULL, workflow_changed_by INTEGER NULL, workflow_changed_at DATETIME NULL, created_at DATETIME, updated_at DATETIME, UNIQUE(company_id, turo_trip_normalized_id))');
        $this->connection->query('CREATE TABLE ' . $this->table('operational_fact_audits') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER, table_name VARCHAR(80), record_id INTEGER, action VARCHAR(60), old_values TEXT, new_values TEXT, actor_user_id INTEGER, created_at DATETIME)');
    }

    private function seed(): void
    {
        $this->connection->table('companies')->insertBatch([['id' => 1, 'name' => 'Company A'], ['id' => 2, 'name' => 'Company B']]);
        $this->connection->table('fleet_vehicles')->insertBatch([
            ['id' => 10, 'company_id' => 1, 'fleet_code' => 'SYNTH-10', 'display_name' => 'Synthetic Tesla', 'vin' => 'SYNTHETICVIN00001'],
            ['id' => 20, 'company_id' => 2, 'fleet_code' => 'SYNTH-20', 'display_name' => 'Other Company Tesla', 'vin' => 'OTHERCOMPANYVIN001'],
        ]);
        $this->connection->table('lookup_values')->insert(['id' => 10, 'code' => 'booked']);
        $this->connection->table('turo_trips_normalized')->insert([
            'id' => 100, 'fleet_vehicle_id' => 10, 'trip_status_lookup_value_id' => 10, 'turo_trip_id' => 'SYNTH-TRIP-100', 'turo_reservation_id' => 'SYNTH-RES-100', 'guest_name' => 'Synthetic Guest',
            'starts_at' => '2026-09-25 10:00:00', 'ends_at' => '2026-09-25 18:00:00', 'on_trip_ev_charging_amount' => '0.00', 'post_trip_ev_charging_amount' => '99.00',
        ]);
    }

    private function table(string $table): string
    {
        return $this->connection->prefixTable($table);
    }
}

final class TeslaChargingCustodyStub extends CurrentVehicleCustodyService
{
    /** @var array<string, mixed> */
    public array $resolved = [];

    public function resolve(int $vehicleId, ?DateTimeImmutable $asOf = null): array
    {
        return $this->resolved;
    }
}
