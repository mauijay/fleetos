<?php

use App\Repositories\VehicleDamageRepairEstimateRepository as Estimates;
use App\Services\Fleet\VehicleDamageRepairEstimateService;
use App\Services\Fleet\VehicleDamageRepairService;
use CodeIgniter\HTTP\Files\UploadedFile;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\Support\VehicleDamageRepairTestCase;

/** @internal */
#[PreserveGlobalState(false)]
#[RunTestsInSeparateProcesses]
final class VehicleDamageRepairEstimatesTest extends VehicleDamageRepairTestCase
{
    private array $uploads = [];

    protected function tearDown(): void
    {
        foreach ($this->uploads as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        $documents = new \App\Repositories\VehicleDamageRepairDocumentRepository($this->connection);
        $storage = new \App\Services\Files\RepairDocumentStorageService($this->connection);
        foreach ($this->connection->table(Estimates::DOCUMENTS)->get()->getResultArray() as $document) {
            $resolved = $storage->resolve((int) $document['company_id'], $document, $documents->metadata($document));
            if ($resolved !== null && is_file($resolved['path'])) {
                unlink($resolved['path']);
            }
        }
        parent::tearDown();
    }

    private function quote(int $job, bool $current = false, array $extra = []): array
    {
        $data = ['quote_series_key' => VehicleDamageRepairService::commandKey(), 'recording_mode' => $current ? 'current_quote' : 'historical_incomplete',
            'amount' => '125.5', 'currency' => 'USD', 'amount_confirmed' => '1', 'currency_confirmed' => '1',
            'scope_membership_ids' => array_column($this->repairs->members(1, 10, $job), 'id'), 'historical_recording_reason' => 'Synthetic incomplete source'];
        if ($current) {
            $path = tempnam(sys_get_temp_dir(), 'b22_');
            file_put_contents($path, "%PDF-1.4\n% Synthetic source " . bin2hex(random_bytes(16)) . "\n%%EOF\n");
            $this->uploads[] = $path;
            $data += ['vendor_snapshot' => 'Synthetic repair vendor', 'quote_date' => '2026-10-06',
                'vendor_confirmed' => '1', 'date_confirmed' => '1', 'scope_confirmed' => '1',
                'document' => ['kind_code' => 'estimate', 'upload' => new UploadedFile($path, 'synthetic-quote.pdf', 'application/pdf', filesize($path), UPLOAD_ERR_OK)]];
        }
        return $this->command($job, array_replace($data, $extra));
    }

    private function decision(int $job, int $estimate, array $extra = []): array
    {
        return $this->command($job, $extra + ['estimate_id' => $estimate, 'expected_estimate_state' => (new Estimates($this->connection))->fingerprint(1, 10, $job, $estimate), 'confirmed' => '1', 'reason' => 'Synthetic decision']);
    }

    public function testHistoricalAtomicScopeReplayAndHardAcceptanceGate(): void
    {
        $job = $this->createWork([$this->condition(), $this->condition()]);
        $repo = new Estimates($this->connection);
        $this->assertTrue($repo->ready());
        $data = $this->quote($job, false, ['amount' => '0', 'reason_category_code' => 'synthetic_quote', 'recording_reason' => 'Synthetic unrelated work-only reason']);
        $before = $this->counts();
        $result = $this->work->createEstimate(1, 10, $job, $data, 7);
        $this->success($result);
        $estimate = $repo->estimate(1, 10, $job, $result['estimate_id']);
        $this->assertSame('0.00', $estimate['amount']);
        $this->assertNull($estimate['vendor_snapshot']);
        $this->assertNull($this->repairs->job(1, 10, $job)['accepted_estimate_id']);
        $this->assertCount(2, $repo->scope(1, $job));
        $this->assertSame($before['audit_logs'] + 4, $this->counts()['audit_logs']);
        $events = $this->repairs->events(1, 10, $job);
        $event = end($events);
        $this->assertSame('synthetic_quote', $event['reason_category_code']);
        $this->assertNull($event['reason']);
        $saved = $this->counts();
        $this->assertTrue($this->work->createEstimate(1, 10, $job, $data, 7)['replayed']);
        $this->assertSame($saved, $this->counts());
        $this->failure($this->work->createEstimate(1, 10, $job, array_replace($data, ['reason_category_code' => 'synthetic_changed']), 7), 'different payload');
        $this->assertTrue($this->work->createEstimate(1, 10, $job, array_replace($data, ['recording_reason' => 'Synthetic ignored change']), 7)['replayed']);
        $this->assertSame($saved, $this->counts());
        $this->failure($this->work->createEstimate(1, 10, $job, array_replace($data, ['amount' => '1']), 7), 'different payload');
        $this->failure($this->work->acceptEstimate(1, 10, $job, $this->decision($job, $result['estimate_id']), 7), 'authoritative');
        $this->assertSame($saved, $this->counts());
    }

    public function testCurrentQuoteUploadReplayAcceptanceRevisionAndCompetingSelection(): void
    {
        $job = $this->createWork([$this->condition(), $this->condition()]);
        $repo = new Estimates($this->connection);
        $data = $this->quote($job, true);
        $created = $this->work->createEstimate(1, 10, $job, $data, 7);
        $this->success($created);
        $data['document']['descriptor'] = $created['source_descriptor'];
        $this->assertTrue($this->work->createEstimate(1, 10, $job, $data, 7)['replayed']);
        $accept = $this->decision($job, $created['estimate_id']);
        $this->success($this->work->acceptEstimate(1, 10, $job, $accept, 7));
        $old = $repo->estimate(1, 10, $job, $created['estimate_id']);
        $revision = $this->quote($job, true, ['quote_series_key' => $old['quote_series_key'], 'previous_estimate_id' => $old['id'],
            'expected_estimate_state' => $repo->fingerprint(1, 10, $job, (int) $old['id']), 'amount' => '9999999999.99']);
        $new = $this->work->createRevision(1, 10, $job, $revision, 7);
        $this->success($new);
        $this->assertSame('accepted', $repo->estimate(1, 10, $job, (int) $old['id'])['status_code']);
        $this->failure($this->work->createRevision(1, 10, $job, $this->command($job, $revision + []), 7));
        $replace = $this->decision($job, $new['estimate_id'], ['previous_accepted_estimate_id' => $old['id'], 'expected_previous_accepted_state' => $repo->fingerprint(1, 10, $job, (int) $old['id'])]);
        $this->success($this->work->acceptEstimate(1, 10, $job, $replace, 7));
        $this->assertSame('superseded', $repo->estimate(1, 10, $job, (int) $old['id'])['status_code']);
        $competing = $this->work->createEstimate(1, 10, $job, $this->quote($job, true, ['vendor_snapshot' => 'Synthetic competing vendor']), 7);
        $this->success($competing);
        $this->success($this->work->acceptEstimate(1, 10, $job, $this->decision($job, $competing['estimate_id'], ['previous_accepted_estimate_id' => $new['estimate_id'], 'expected_previous_accepted_state' => $repo->fingerprint(1, 10, $job, $new['estimate_id'])]), 7));
        $this->assertSame('withdrawn', $repo->estimate(1, 10, $job, $new['estimate_id'])['status_code']);
        $this->success($this->work->withdrawEstimate(1, 10, $job, $this->decision($job, $competing['estimate_id']), 7));
        $this->assertNull($this->repairs->job(1, 10, $job)['accepted_estimate_id']);
    }

    public function testInvalidFactsAndScopeRollbackWithoutVersionOrEvent(): void
    {
        $job = $this->createWork([$this->condition()]);
        $base = $this->quote($job);
        foreach ([['amount' => 1.25], ['amount' => '-1'], ['amount' => '1e3'], ['amount' => '1,000'], ['amount' => '1.001'], ['amount' => '10000000000'],
            ['currency' => 'EUR'], ['historical_recording_reason' => ''], ['scope_membership_ids' => []], ['scope_membership_ids' => [$base['scope_membership_ids'][0], $base['scope_membership_ids'][0]]], ['scope_membership_ids' => [999]], ['amount_confirmed' => '0'], ['expires_at' => '2026-10-07 01:00:00', 'expires_on' => '2026-10-07'], ['quote_date' => '2026-02-30']] as $invalid) {
            $before = $this->counts();
            $this->failure($this->work->createEstimate(1, 10, $job, array_replace($base, $invalid), 7));
            $this->assertSame($before, $this->counts());
            $this->assertSame(0, $this->connection->table(Estimates::ESTIMATES)->countAllResults());
        }
        $current = $this->quote($job, true);
        unset($current['document']);
        $this->failure($this->work->createEstimate(1, 10, $job, $current, 7), 'source');
        $this->assertSame(0, $this->connection->table(Estimates::ESTIMATES)->countAllResults());

        $created = $this->work->createEstimate(1, 10, $job, $this->quote($job, true), 7);
        $this->success($created);
        // Simulate incomplete restored data in this disposable fixture: acceptance
        // must fail closed even when quote facts and its source are otherwise valid.
        $this->connection->table(Estimates::SCOPE)->where('vehicle_damage_repair_estimate_id', $created['estimate_id'])->delete();
        $before = $this->counts();
        $this->failure($this->work->acceptEstimate(1, 10, $job, $this->decision($job, $created['estimate_id']), 7), 'nonempty frozen quote scope');
        $this->assertSame($before, $this->counts());
        $this->assertNull($this->repairs->job(1, 10, $job)['accepted_estimate_id']);
    }

    public function testFrozenScopeStaleMembershipAndArchiveSupport(): void
    {
        $job = $this->createWork([$this->condition(), $this->condition()]);
        $created = $this->work->createEstimate(1, 10, $job, $this->quote($job, true), 7);
        $this->success($created);
        $repo = new Estimates($this->connection);
        $scope = $repo->scope(1, $job);
        $decision = $this->decision($job, $created['estimate_id']);
        $member = $this->repairs->members(1, 10, $job)[0];
        $this->success($this->work->withdrawCondition(1, 10, $job, $this->command($job, ['membership_id' => $member['id'], 'reason' => 'Synthetic scope withdrawal']), 7));
        $this->failure($this->work->acceptEstimate(1, 10, $job, $decision, 7), 'changed');
        $this->failure($this->work->acceptEstimate(1, 10, $job, $this->decision($job, $created['estimate_id']), 7), 'current');
        $this->assertSame($scope, $repo->scope(1, $job));
        $documents = new \App\Repositories\VehicleDamageRepairDocumentRepository($this->connection);
        $doc = $created['document_id'];
        $this->success($this->work->archiveDocument(1, 10, $job, $this->command($job, ['document_id' => $doc, 'expected_document_state' => $documents->fingerprint(1, 10, $job, $doc), 'confirmed' => '1', 'reason' => 'Synthetic archive']), 7));
        $archived = $documents->document(1, 10, $job, $doc);
        $this->assertNotNull((new \App\Services\Files\RepairDocumentStorageService($this->connection))->resolve(1, $archived, $documents->metadata($archived)));
        $this->assertNull($documents->document(2, 10, $job, $doc));
    }

    public function testExpiryIsDerivedAtExactBoundaryAndNeverWrites(): void
    {
        $instant = ['expires_at' => '2026-10-07 12:00:00', 'expires_on' => null];
        $this->assertSame('valid', VehicleDamageRepairEstimateService::validity($instant, new DateTimeImmutable('2026-10-07 11:59:59')));
        $this->assertSame('expired', VehicleDamageRepairEstimateService::validity($instant, new DateTimeImmutable('2026-10-07 12:00:00')));
        $date = ['expires_at' => null, 'expires_on' => '2026-10-07'];
        $this->assertSame('valid', VehicleDamageRepairEstimateService::validity($date, new DateTimeImmutable('2026-10-07 23:59:59')));
        $this->assertSame('expired', VehicleDamageRepairEstimateService::validity($date, new DateTimeImmutable('2026-10-08')));
    }

    public function testAcceptedQuoteRetainsFrozenPartialScopeAndSelectionAfterSourceAndMembershipChanges(): void
    {
        $job = $this->createWork([$this->condition(), $this->condition()]);
        $members = $this->repairs->members(1, 10, $job);
        $first = $this->work->createEstimate(1, 10, $job, $this->quote($job, true, ['scope_membership_ids' => [$members[0]['id']]]), 7);
        $this->success($first);
        $repo = new Estimates($this->connection);
        $frozen = $repo->scope(1, $job);
        $this->assertCount(1, $frozen);
        $this->success($this->work->acceptEstimate(1, 10, $job, $this->decision($job, $first['estimate_id']), 7));
        $docs = new \App\Repositories\VehicleDamageRepairDocumentRepository($this->connection);
        $this->success($this->work->archiveDocument(1, 10, $job, $this->command($job, ['document_id' => $first['document_id'], 'expected_document_state' => $docs->fingerprint(1, 10, $job, $first['document_id']), 'confirmed' => '1', 'reason' => 'Synthetic accepted-source archival']), 7));
        $this->success($this->work->withdrawCondition(1, 10, $job, $this->command($job, ['membership_id' => $members[0]['id'], 'reason' => 'Synthetic post-acceptance scope change']), 7));
        $this->assertSame($first['estimate_id'], (int) $this->repairs->job(1, 10, $job)['accepted_estimate_id']);
        $this->assertSame('accepted', $repo->estimate(1, 10, $job, $first['estimate_id'])['status_code']);
        $this->assertSame($frozen, $repo->scope(1, $job));
        $old = $repo->estimate(1, 10, $job, $first['estimate_id']);
        $next = $this->work->createRevision(1, 10, $job, $this->quote($job, true, ['quote_series_key' => $old['quote_series_key'], 'previous_estimate_id' => $first['estimate_id'], 'expected_estimate_state' => $repo->fingerprint(1, 10, $job, $first['estimate_id']), 'scope_membership_ids' => [$members[1]['id']]]), 7);
        $this->success($next);
        $this->success($this->work->acceptEstimate(1, 10, $job, $this->decision($job, $next['estimate_id'], ['previous_accepted_estimate_id' => $first['estimate_id'], 'expected_previous_accepted_state' => $repo->fingerprint(1, 10, $job, $first['estimate_id'])]), 7));
        $this->assertSame('superseded', $repo->estimate(1, 10, $job, $first['estimate_id'])['status_code']);
        $this->assertSame($frozen[0], $repo->scope(1, $job, $first['estimate_id'])[0]);
    }
}
