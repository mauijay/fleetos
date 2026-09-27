<?php

use CodeIgniter\Config\Services as CoreServices;
use CodeIgniter\Shield\Auth;
use CodeIgniter\Shield\Config\Auth as AuthConfig;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;

/** @internal */
final class SuperchargerReconciliationViewTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Services::injectMock('auth', new SuperchargerReconciliationViewTestAuth());
    }

    protected function tearDown(): void
    {
        Services::reset();
        parent::tearDown();
    }

    public function testTripCaseShowsDerivedMoneySourceDetailAndSeparateWorkflow(): void
    {
        $html = CoreServices::renderer()->setData($this->data())->render('supercharger_reconciliation/index');

        $this->assertStringContainsString('Supercharger Reimbursements', $html);
        $this->assertStringContainsString('Eligible Tesla cost', $html);
        $this->assertStringContainsString('Turo On-trip EV charging', $html);
        $this->assertStringContainsString('Post-trip EV charging', $html);
        $this->assertStringContainsString('excluded from this match', $html);
        $this->assertStringContainsString('Tesla source detail · 1 line', $html);
        $this->assertStringContainsString('Invoice workflow', $html);
        $this->assertStringContainsString('Submitted', $html);
        $this->assertStringNotContainsString('missed', strtolower($html));
        $this->assertStringNotContainsString('expired', strtolower($html));
    }

    public function testAllOperationalFiltersAndCsvImportAreVisible(): void
    {
        $html = CoreServices::renderer()->setData($this->data())->render('supercharger_reconciliation/index');

        foreach (['Needs attention', 'Reconciled', 'Host expense', 'Review', 'All'] as $label) {
            $this->assertStringContainsString($label, $html);
        }
        $this->assertStringContainsString('name="tesla_csv"', $html);
        $this->assertStringContainsString('accept=".csv,text/csv,.txt"', $html);
        $this->assertStringContainsString('as CSV', $html);
    }

    public function testHostAndReviewSessionsExplainCustodyWithoutInventingEligibility(): void
    {
        $data = $this->data();
        $data['workspace']['cases'] = [];
        $data['workspace']['sessions'] = [
            $this->session('host_expense', 'between_trips', 'authoritative_operator_between_trip_gap'),
            $this->session('review', 'review', 'schedule_only_candidate', 'SYNTH-CANDIDATE'),
        ];
        $html = CoreServices::renderer()->setData($data)->render('supercharger_reconciliation/index');

        $this->assertStringContainsString('Host expense — charging occurred outside guest custody.', $html);
        $this->assertStringContainsString('Review required — authoritative custody is not confirmed.', $html);
        $this->assertStringContainsString('candidate trip SYNTH-CANDIDATE', $html);
        $this->assertStringNotContainsString('excluded from this match', $html);
    }

    /** @return array<string, mixed> */
    private function data(): array
    {
        return [
            'assets' => ['css' => null, 'js' => null],
            'navigation' => [],
            'notice' => null,
            'error' => null,
            'workspace' => [
                'filter' => 'all',
                'summary' => ['attention' => 1, 'reconciled' => 0, 'host' => 0, 'review' => 0, 'all' => 1],
                'sessions' => [],
                'import_issues' => [],
                'cases' => [[
                    'id' => 1,
                    'vehicle_name' => 'Synthetic Space10',
                    'fleet_code' => 'SPACE10-SYNTH',
                    'guest_name' => 'Synthetic Guest',
                    'turo_trip_id' => 'SYNTH-TRIP',
                    'financial_status' => 'eligible_unreconciled',
                    'eligible_cost_cents' => 725,
                    'turo_reimbursed_cents' => 0,
                    'outstanding_cents' => 725,
                    'workflow_state_code' => 'submitted',
                    'workflow_note' => null,
                    'invoice_reference' => null,
                    'post_trip_ev_charging_amount' => '20.00',
                    'audits' => [],
                    'line_items' => [[
                        'fee_description' => 'CONGESTION PAYMENT',
                        'total_inc_vat_amount' => '7.25',
                        'source_started_at' => '2026-09-25T13:15:00-10:00',
                        'site_location_name' => 'Synthetic Supercharger',
                        'invoice_number' => 'INV-SYNTH',
                        'custody_classification' => 'guest',
                        'custody_basis_code' => 'actual_handoff',
                        'invoice_url' => null,
                    ]],
                ]],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function session(string $financialStatus, string $classification, string $basis, ?string $candidate = null): array
    {
        return [
            'vehicle_name' => 'Synthetic Tesla',
            'fleet_code' => 'SYNTH-TESLA',
            'source_started_at' => '2026-09-25T20:00:00-10:00',
            'cost_amount' => '8.00',
            'financial_status' => $financialStatus,
            'custody_classification' => $classification,
            'custody_basis_code' => $basis,
            'charging_location' => 'Synthetic Supercharger',
            'candidate_turo_trip_id' => $candidate,
        ];
    }
}

final class SuperchargerReconciliationViewTestAuth extends Auth
{
    public function __construct()
    {
        parent::__construct(new AuthConfig());
    }

    public function setAuthenticator(?string $alias = null): self
    {
        return $this;
    }

    public function loggedIn(): bool
    {
        return false;
    }

    public function user(): ?User
    {
        return null;
    }
}
