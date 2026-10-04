<?php

use App\Services\Fleet\ExtrasVerificationFreshnessPolicy;
use App\Services\Fleet\MovementReadinessProjectionService;
use CodeIgniter\Test\CIUnitTestCase;
use Config\ExtrasVerification;
use PHPUnit\Framework\Attributes\DataProvider;

/** @internal */
final class ExtrasVerificationFreshnessPolicyTest extends CIUnitTestCase
{
    #[DataProvider('scenarios')]
    public function testPolicyBoundariesAndEvidence(?int $ageSeconds, int $pickupSeconds, int $count, ?int $issueAge, string $state, bool $required, bool $advisory): void
    {
        $asOf = new DateTimeImmutable('2030-01-02 12:00:00 Pacific/Honolulu');
        $source = $ageSeconds === null ? null : $asOf->modify('-' . $ageSeconds . ' seconds')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $issueAt = $issueAge === null ? null : $asOf->modify('-' . $issueAge . ' seconds')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $result = (new ExtrasVerificationFreshnessPolicy())->assess([
            'observed_at' => $source, 'extra_count' => $count,
            'issue' => $issueAt === null ? null : 'Synthetic failed/incomplete attempt', 'issue_observed_at' => $issueAt,
        ], $this->trip($asOf->modify('+' . $pickupSeconds . ' seconds')->format('Y-m-d H:i:s')), $asOf, 'Pacific/Honolulu');
        $this->assertSame($state, $result['state']);
        $this->assertSame($required, $result['refresh_required']);
        $this->assertSame($advisory, $result['advisory']);
        $this->assertSame($source, $result['observed_at']);
        $this->assertSame($ageSeconds, $result['age_seconds']);
        $this->assertSame('/turo/extras?reservation_id=70000001#export-heading', $result['action_href']);
        if ($required) {
            $projection = $this->project($result);
            $this->assertFalse($projection['ready']);
            $this->assertSame(1, $projection['blocking_remaining_count']);
            $this->assertSame('Refresh Turo Extras', $projection['requirements'][0]['action']['label']);
        }
    }

    public static function scenarios(): array
    {
        return [
            'never far future' => [null, 604800, 0, null, 'never', false, false],
            'never advisory' => [null, 172800, 0, null, 'never', false, true],
            'never near pickup' => [null, 7200, 0, null, 'never', true, false],
            'fresh empty' => [3600, 7200, 0, null, 'current_empty', false, false],
            'stale empty hard regression' => [1440000, 7200, 0, null, 'stale_empty', true, false],
            'fresh nonempty' => [3600, 7200, 1, null, 'current_nonempty', false, false],
            'stale nonempty' => [90000, 7200, 1, null, 'stale_nonempty', true, false],
            'fresh complete newer failed' => [7200, 7200, 0, 3600, 'current_empty', true, false],
            'stale complete newer failed' => [90000, 7200, 0, 3600, 'stale_empty', true, false],
            'exact 24 hour age qualifies' => [86400, 0, 0, null, 'current_empty', false, false],
            'over 24 hour age' => [86401, 0, 0, null, 'stale_empty', true, false],
            'preparation window opens exactly' => [null, 86400, 0, null, 'never', true, false],
            'before preparation' => [null, 86401, 0, null, 'never', false, true],
            'exact advisory horizon' => [null, 259200, 0, null, 'never', false, true],
            'beyond advisory horizon' => [null, 259201, 0, null, 'never', false, false],
            'recent but before preparation' => [7200, 86400, 0, null, 'current_empty', true, false],
            'equal time conflict' => [7200, 7200, 0, 7200, 'current_empty', true, false],
            'older issue superseded' => [3600, 7200, 0, 7200, 'current_empty', false, false],
            'overdue pickup missing handoff' => [null, -7200, 0, null, 'never', true, false],
        ];
    }

    public function testUtcAndHonoluluClocksAgreeAndFutureEvidenceIsUntrusted(): void
    {
        $policy = new ExtrasVerificationFreshnessPolicy();
        $evidence = ['observed_at' => '2030-01-02 21:00:00', 'extra_count' => 0];
        $local = $policy->assess($evidence, $this->trip(), new DateTimeImmutable('2030-01-02 12:00:00 Pacific/Honolulu'), 'Pacific/Honolulu');
        $utc = $policy->assess($evidence, $this->trip(), new DateTimeImmutable('2030-01-02 22:00:00 UTC'), 'Pacific/Honolulu');
        foreach (['state', 'age_seconds', 'refresh_required', 'verified_label'] as $field) {
            $this->assertSame($local[$field], $utc[$field]);
        }
        $this->assertStringContainsString('11:00:00 AM HST', $utc['verified_label']);
        $future = $policy->assess(['observed_at' => '2030-01-02 23:00:00'], $this->trip(), new DateTimeImmutable('2030-01-02 22:00:00 UTC'), 'Pacific/Honolulu');
        $this->assertFalse($future['observation_trusted']);
        $this->assertSame(-3600, $future['age_seconds']);
        $this->assertTrue($future['refresh_required']);
        $this->assertStringContainsString('untrusted', $future['summary']);
    }

    public function testReplayMetadataCannotMakeOldSourceFreshAndStaleEmptyUiIsExplicit(): void
    {
        $source = ['observed_at' => '2029-12-17 22:00:00', 'extra_count' => 0, 'created_at' => '2030-01-02 22:00:00'];
        $model = $this->assess($source);
        $this->assertSame('stale_empty', $model['state']);
        $this->assertTrue($model['refresh_required']);
        $this->assertStringNotContainsString('No Extras observed at', $model['summary']);
        $html = \Config\Services::renderer()->setData(['verification' => $model])->render('trip_movement_checklists/_extras_verification');
        $this->assertStringContainsString('Extras verification stale', $html);
        $this->assertStringContainsString('Refresh required before pickup', $html);
        $this->assertStringContainsString('reservation_id=70000001#export-heading', html_entity_decode($html));
        $this->assertStringNotContainsString('<form', $html);
    }

    public function testClosedLifecycleNeverCreatesHistoricalPickupDeficit(): void
    {
        foreach ([['has_actual_handoff' => true, 'trip_status_code' => 'in_progress'], ['trip_status_code' => 'completed'], ['canceled_at' => '2030-01-01'], ['deleted_at' => '2030-01-01'], ['trip_status_code' => 'invalid']] as $lifecycle) {
            $model = $this->assess(['observed_at' => '2029-12-17 22:00:00'], $lifecycle);
            $this->assertFalse($model['refresh_required']);
            $this->assertFalse($model['advisory']);
            $projection = $this->project($model);
            $this->assertTrue($projection['ready']);
            $this->assertSame('not_applicable', $projection['requirements'][0]['status']);
        }
    }

    public function testBrowserRefreshSurvivesOtherTripCustodyAndFulfillmentStaysIndependent(): void
    {
        $model = $this->assess([]);
        $fulfillment = ['company_id' => 1, 'turo_trip_normalized_id' => 100, 'fleet_vehicle_id' => 9, 'fulfillment_id' => 7,
            'title' => 'Synthetic kit', 'fulfillment_phase' => 'preparation', 'requires_operator_confirmation' => true,
            'readiness_blocking' => true, 'is_completed' => false, 'is_actionable' => true];
        $projection = $this->project($model, ['extra_fulfillments' => [$fulfillment],
            'latest_custody_event' => ['event_code' => 'actual_handoff', 'turo_trip_normalized_id' => 99]]);
        $this->assertSame(2, $projection['blocking_remaining_count']);
        $this->assertTrue($projection['requirements'][0]['actionable']);
        $this->assertSame('extras_verification', $projection['requirements'][0]['action']['type']);
        $this->assertFalse($projection['requirements'][1]['actionable']);
        $fulfillment['is_completed'] = true;
        $fulfilled = $this->project($model, ['extra_fulfillments' => [$fulfillment]]);
        $this->assertSame(1, $fulfilled['blocking_remaining_count']);
        $this->assertSame('satisfied', $fulfilled['requirements'][1]['status']);
        $fresh = $this->project($this->assess(['observed_at' => '2030-01-02 21:00:00']), ['extra_fulfillments' => [array_merge($fulfillment, ['is_completed' => false])]]);
        $this->assertSame(1, $fresh['blocking_remaining_count']);
        $this->assertSame('satisfied', $fresh['requirements'][0]['status']);
    }

    public function testCompanyOwnershipAndStableTripIdentity(): void
    {
        $model = $this->assess([]);
        $own = $this->project($model);
        $this->assertCount(1, $own['requirements']);
        $this->assertSame('1:100:extras_verification', $own['requirements'][0]['work_identity']);
        $this->assertSame([], $this->project(array_merge($model, ['company_id' => 2]))['requirements']);
        $this->assertSame([], $this->project(array_merge($model, ['trip_id' => 101]))['requirements']);
        $this->assertSame([], $this->project(array_merge($model, ['vehicle_id' => 10]))['requirements']);
    }

    public function testConfigIsCentralizedAndValidated(): void
    {
        $config = new ExtrasVerification();
        $config->maxCompleteAgeHours = 12;
        $model = (new ExtrasVerificationFreshnessPolicy($config))->assess(['observed_at' => '2030-01-02 09:00:00'], $this->trip(), new DateTimeImmutable('2030-01-02 22:00:00 UTC'), 'Pacific/Honolulu');
        $this->assertTrue($model['is_stale']);
        $config->advisoryHorizonHours = 1;
        $this->expectException(InvalidArgumentException::class);
        new ExtrasVerificationFreshnessPolicy($config);
    }

    private function trip(string $pickup = '2030-01-02 14:00:00'): array
    {
        return ['company_id' => 1, 'trip_id' => 100, 'fleet_vehicle_id' => 9, 'reservation_id' => '70000001', 'starts_at' => $pickup];
    }

    private function assess(array $evidence, array $trip = []): array
    {
        return (new ExtrasVerificationFreshnessPolicy())->assess($evidence, array_merge($this->trip(), $trip), new DateTimeImmutable('2030-01-02 12:00:00 Pacific/Honolulu'), 'Pacific/Honolulu');
    }

    private function project(array $verification, array $context = []): array
    {
        return (new MovementReadinessProjectionService())->projectTripPreparation(array_merge([
            'company_id' => 1, 'turo_trip_normalized_id' => 100, 'fleet_vehicle_id' => 9,
            'extra_verification' => $verification, 'extra_fulfillments' => [], 'active_commitments' => [], 'active_events' => [],
        ], $context));
    }
}
