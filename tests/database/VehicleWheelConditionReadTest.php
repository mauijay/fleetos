<?php

namespace Tests\Database;

use App\Repositories\VehicleDamageRepository;
use App\Repositories\VehicleWheelReadRepository;
use App\Services\Fleet\VehicleDamageOccurrenceEvidenceService;
use App\Services\Fleet\VehicleDamageService;
use App\Services\Fleet\VehicleWheelConditionReadService as Reads;
use App\Services\Fleet\VehicleWheelEpisodeGuard;
use App\Services\Fleet\VehicleWheelIdentity;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\VehicleWheelDamageFixture;

final class VehicleWheelConditionReadTest extends CIUnitTestCase
{
    private ?VehicleWheelDamageFixture $fixture = null;

    protected function tearDown(): void
    {
        $this->fixture?->close();
        parent::tearDown();
    }

    public static function engines(): array
    {
        return ['sqlite' => ['sqlite'],'maria' => ['maria']];
    }

    private function cloneCondition(array $original, array $changes = []): int
    {
        $db = $this->fixture->schema->db;
        unset($original['id']);
        $db->table('vehicle_damage_items')->insert(array_replace($original, $changes));
        return (int)$db->insertID();
    }

    private function success(array $receipt): array
    {
        self::assertTrue($receipt['success'], json_encode($receipt));
        return $receipt;
    }

    private function denied(callable $operation): void
    {
        $before = $this->fixture->schema->rows();
        try {
            $operation();
            self::fail('Owned parent or valid filter required.');
        } catch (InvalidArgumentException) {
            self::assertSame($before, $this->fixture->schema->rows());
        }
    }

    #[DataProvider('engines')]
    public function testProjectionHistoryFiltersAndReadFirewall(string $engine): void
    {
        $f = $this->fixture = new VehicleWheelDamageFixture($engine);
        $db = $f->schema->db;
        $service = $f->service;
        $damage = new VehicleDamageService($db);
        $items = new VehicleDamageRepository($db);
        $reads = new Reads($db);
        $new = $this->success($service->createNew(1, 10, $f->data('LF', ['trip_id' => 100,'attribution_type' => 'discovered_during_trip']), 7));
        $worse = $this->success($service->recordWorsening(1, 10, $f->data('LF', ['trip_id' => 100,'discovered_at' => '2026-10-02 09:00:00','note' => 'First delta at 2 o\'clock.']), 7));
        $worse2 = $this->success($service->recordWorsening(1, 10, $f->data('LF', ['trip_id' => 100,'discovered_at' => '2026-10-03 09:00:00','severity_code' => 'severe','note' => 'Second delta at 8 o\'clock.']), 7));
        $observe = $this->success($service->confirmUnchanged(1, 10, $f->data('LF', ['trip_id' => 100,'observed_at' => '2026-10-04 09:00:00','severity_code' => null,'note' => 'Explicit unchanged observation.']), 7));
        $rf = $this->success($service->createNew(1, 10, $f->data('RF'), 7));
        $this->success($damage->transitionStatus(1, 10, $rf['condition_id'], 'accepted_unrepaired', 'Synthetic acceptance.', 7));
        $rr = $this->success($service->createNew(1, 10, $f->data('RR'), 7));
        $this->success($damage->transitionStatus(1, 10, $rr['condition_id'], 'repaired', 'Synthetic physical repair.', 7));
        $rr2 = $this->success($service->createNew(1, 10, $f->data('RR', ['discovered_at' => '2026-10-20 10:00:00']), 7));
        $this->success($damage->transitionStatus(1, 10, $rr2['condition_id'], 'resolved_other', 'Synthetic resolution.', 7));
        $lr = $this->success($service->createNew(1, 10, $f->data('LR'), 7));
        $lrRow = $db->table('vehicle_damage_items')->where('id', $lr['condition_id'])->get()->getRowArray();
        $conflict = $this->cloneCondition($lrRow);
        $unknown = $this->success($damage->create(1, 10, ['zone_code' => 'wheel_tire','panel_code' => null,'damage_type_code' => 'wheel_curb_rash','severity_code' => 'cosmetic','description' => 'Legacy unknown position.','discovered_at' => '2026-10-01 10:00:00'], 7));
        $root = $db->table('vehicle_damage_items')->where('id', $new['condition_id'])->get()->getRowArray();
        $db->table('vehicle_damage_repair_jobs')->insert(['company_id' => 1,'fleet_vehicle_id' => 10,'intent_code' => 'repair','category_code' => 'wheel','summary' => 'Synthetic partial wheel work','status_code' => 'completed','creation_command_key' => \App\Services\Fleet\VehicleDamageRepairService::commandKey(),'creation_command_payload_hash' => str_repeat('a', 64),'created_by' => 7,'updated_by' => 7,'created_at' => '2026-10-05 10:00:00','updated_at' => '2026-10-05 10:00:00']);
        $job = (int)$db->insertID();
        $db->table('vehicle_damage_repair_job_items')->insertBatch(array_map(static fn (array $pair): array => ['company_id' => 1,'vehicle_damage_repair_job_id' => $job,'vehicle_damage_item_id' => $pair[0],'result_code' => $pair[1],'created_by' => 7,'updated_by' => 7,'created_at' => '2026-10-05 10:00:00','updated_at' => '2026-10-05 10:00:00'], [[$new['condition_id'],'unchanged'],[$rf['condition_id'],'unassessed'],[$rr['condition_id'],'repaired']]));
        $before = $f->schema->rows();
        $projection = $reads->forVehicles(1, [10,11,20], [10 => 100]);
        self::assertSame([10,11], array_keys($projection));
        self::assertSame(['LF','RF','RR','LR'], array_keys($projection[10]['wheels']));
        $wheels = $projection[10]['wheels'];
        self::assertSame('Open rash', $wheels['LF']['status']);
        self::assertSame('Accepted unrepaired', $wheels['RF']['status']);
        self::assertSame('No active rash recorded', $wheels['RR']['status']);
        self::assertSame('Review conflicting records', $wheels['LR']['status']);
        self::assertNull($wheels['LR']['active_condition_id']);
        self::assertSame(2, $wheels['LR']['conflict_record_count']);
        self::assertSame(1, $projection[10]['unknown']['count']);
        self::assertSame($worse2['membership_id'], (int)$wheels['LF']['latest_physical_occurrence']['id']);
        self::assertSame($observe['membership_id'], (int)$wheels['LF']['latest_observation']['id']);
        self::assertSame($worse2['membership_id'], (int)$wheels['LF']['latest_worsening']['id']);
        self::assertSame('Worsened on this trip', $wheels['LF']['trip_badge']);
        self::assertSame('unchanged', $wheels['LF']['repair_context']['result_code']);
        self::assertSame('open', $wheels['LF']['physical_status']);
        self::assertSame('resolved_other', $wheels['RR']['repair_context']['physical_event_code']);
        self::assertNull($wheels['RR']['repair_context']['job_id']);
        self::assertSame((new VehicleWheelEpisodeGuard($db))->resolve(1, 10, VehicleWheelIdentity::panel('LF'))['fingerprint'], $wheels['LF']['fingerprint']);
        self::assertNull($reads->forVehicles(1, [10], [10 => 102])[10]['wheels']['LF']['trip_badge']);
        self::assertNull($reads->forVehicles(1, [10])[10]['wheels']['LF']['trip_badge']);
        $db->table('vehicle_damage_incidents')->where('id', $worse['incident_id'])->update(['turo_trip_normalized_id' => 102]);
        $db->table('vehicle_damage_incidents')->where('id', $worse2['incident_id'])->update(['turo_trip_normalized_id' => 102]);
        self::assertSame('Observed on this trip', $reads->forVehicles(1, [10], [10 => 100])[10]['wheels']['LF']['trip_badge']);
        $db->table('vehicle_damage_incidents')->whereIn('id', [$worse['incident_id'],$worse2['incident_id']])->update(['turo_trip_normalized_id' => 100]);
        $history = $reads->history(1, 10, 'LF');
        self::assertCount(1, $history['episodes']);
        self::assertCount(4, $history['timeline']);
        self::assertSame([$new['membership_id'],$worse['membership_id'],$worse2['membership_id'],$observe['membership_id']], array_map(static fn (array $row): int => (int)$row['membership_id'], $history['timeline']));
        $rrHistory = $reads->history(1, 10, 'RR');
        self::assertSame([1,2], array_map(static fn (array $row): int => (int)$row['episode_number'], $rrHistory['episodes']));
        self::assertSame(['repaired','resolved_other'], array_column($rrHistory['episodes'], 'status_code'));
        self::assertCount(1, $rrHistory['repairs']);
        $later = $reads->history(1, 10, 'RR', 1, $rr2['condition_id']);
        self::assertSame([], $later['repairs']);
        self::assertContains('resolved_other', array_column($later['timeline'], 'effect'));
        self::assertCount(1, $reads->history(1, 10, 'UNKNOWN')['episodes']);
        self::assertCount(2, $reads->history(1, 10, 'LR')['episodes']);
        foreach (['LF' => 1,'RF' => 1,'RR' => 2,'LR' => 2,'UNKNOWN' => 1] as $wheel => $count) {
            self::assertCount($count, $reads->ledger(1, 10, ['wheel' => $wheel])['results']);
        }
        self::assertCount(1, $reads->ledger(1, 10, ['physical_status' => 'repaired'])['results']);
        self::assertCount(1, $reads->ledger(1, 10, ['physical_status' => 'resolved'])['results']);
        self::assertCount(4, $reads->ledger(1, 10, ['wheel' => 'LF','damage_type' => 'wheel_curb_rash','physical_status' => 'current','identity' => 'recorded'])['results']);
        foreach ([['wheel' => 'XX'],['identity' => 'job'],['physical_status' => 'completed'],['damage_type' => 'bogus'],['wheel' => []],['q' => str_repeat('x', 191)]] as $filter) {
            $this->denied(fn () => $reads->ledger(1, 10, $filter));
        }
        $this->denied(fn () => $reads->history(2, 10, 'LF'));
        $this->denied(fn () => $reads->occurrence(1, 11, $new['condition_id'], $new['membership_id']));
        $this->denied(fn () => $reads->history(1, 10, 'RF', 1, $new['condition_id']));
        $this->denied(fn () => $reads->forVehicles(1, [10], [10 => 101]));
        self::assertSame($before, $f->schema->rows(), 'All read navigation is a zero-write authority firewall.');
        $this->success($damage->reopenRepairedCondition(1, 10, $rr['condition_id'], ['command_key' => \App\Services\Fleet\VehicleDamageRepairService::commandKey(),'expected_condition_state' => (new \App\Repositories\VehicleDamageRepairRepository($db))->conditionFingerprint($items->item(1, 10, $rr['condition_id'])),'confirmed' => 1,'observed_at' => date('Y-m-d H:i:s'),'reason_category_code' => 'repair_failure','note' => 'Synthetic reopening.'], 7));
        self::assertSame('Open rash', $reads->forVehicles(1, [10])[10]['wheels']['RR']['status']);
        self::assertContains('repair_reopened', array_column($reads->history(1, 10, 'RR', 1, $rr['condition_id'])['timeline'], 'effect'));
        $this->success($damage->transitionStatus(1, 10, $rr['condition_id'], 'repaired', 'Synthetic second repair.', 7));
        $rr3 = $this->success($service->createNew(1, 10, $f->data('RR', ['discovered_at' => '2026-10-21 10:00:00']), 7));
        $postRepair = $reads->history(1, 10, 'RR');
        self::assertSame(['repaired','resolved_other','open'], array_column($postRepair['episodes'], 'status_code'));
        self::assertSame($rr3['condition_id'], (int)$postRepair['episode']['id']);
        self::assertSame([], $postRepair['repairs']);
        self::assertNull($postRepair['projection']['wheels']['RR']['repair_context']['job_id']);
        // Older episodes must not occupy the bounded list ahead of actual active conflicts.
        $db->table('vehicle_damage_items')->whereIn('id', [$lr['condition_id'],$conflict])->update(['status_code' => 'resolved_other']);
        for ($n = 0;$n < 30;++$n) {
            $this->cloneCondition($lrRow, ['status_code' => 'resolved_other']);
        }
        $recentA = $this->cloneCondition($lrRow);
        $recentB = $this->cloneCondition($lrRow);
        $review = $reads->forVehicles(1, [10])[10]['wheels']['LR'];
        self::assertCount(25, $review['conflict_records']);
        self::assertSame([$recentA,$recentB], array_map('intval', array_slice(array_column($review['conflict_records'], 'id'), 0, 2)));
        self::assertNull($reads->history(1, 10, 'LR')['episode'], 'Conflict history requires an explicit episode selection.');
        self::assertCount(2, $reads->ledger(1, 10, ['wheel' => 'LR','physical_status' => 'current'])['results']);
        // Owned malformed aliases and foreign incoming pointers are review states, never chosen roots.
        $foreign = $this->cloneCondition($root, ['company_id' => 2,'fleet_vehicle_id' => 20]);
        $alias = $this->cloneCondition($root, ['current_condition_item_id' => $foreign]);
        self::assertSame('conflict', $reads->forVehicles(1, [10])[10]['wheels']['LF']['state']);
        self::assertNull($reads->forVehicles(1, [10])[10]['wheels']['LF']['active_condition_id']);
        $db->table('vehicle_damage_items')->where('id', $alias)->delete();
        $incoming = $this->cloneCondition($root, ['company_id' => 2,'fleet_vehicle_id' => 20,'current_condition_item_id' => $new['condition_id']]);
        self::assertSame('conflict', $reads->forVehicles(1, [10])[10]['wheels']['LF']['state']);
        self::assertNotContains($incoming, array_column($reads->forVehicles(1, [10])[10]['wheels']['LF']['conflict_records'], 'id'));
        $unknownRow = $db->table('vehicle_damage_items')->where('id', $unknown['id'])->get()->getRowArray();
        $unknownAlias = $this->cloneCondition($unknownRow, ['current_condition_item_id' => $new['condition_id']]);
        $unknownProjection = $reads->forVehicles(1, [10])[10]['unknown'];
        self::assertSame(2, $unknownProjection['count']);
        self::assertSame(1, $unknownProjection['invalid_count']);
        self::assertSame($unknownAlias, (int)$reads->history(1, 10, 'UNKNOWN')['projection']['unknown']['invalid_records'][0]['id']);
    }

    #[DataProvider('engines')]
    public function testGalleryOwnershipAvailabilityPaginationAndFrozenBasis(string $engine): void
    {
        $f = $this->fixture = new VehicleWheelDamageFixture($engine);
        $db = $f->schema->db;
        $reads = new Reads($db);
        $receipt = $this->success($f->service->createNew(1, 10, $f->data('LF', ['photos' => [['role' => 'full_wheel','upload' => $f->upload()],['role' => 'close_up','upload' => $f->upload('webp')]]]), 7));
        $basis = $f->member($receipt)['basis_snapshot'];
        $event = $receipt['condition_event_id'];
        $late = $this->success($f->service->attachPhotos(1, 10, $receipt['condition_id'], $receipt['membership_id'], ['command_uuid' => \App\Services\Fleet\VehicleDamageRepairService::commandKey(),'photos' => [['role' => 'close_up','upload' => $f->upload('jpg')]]], 7));
        $photo = $db->table('vehicle_damage_item_evidence')->where('id', $receipt['evidence_ids'][0])->get()->getRowArray();
        $legacy = $photo;
        unset($legacy['id']);
        $legacy['vehicle_damage_incident_item_id'] = null;
        $db->table('vehicle_damage_item_evidence')->insert($legacy);
        $legacyId = (int)$db->insertID();
        $page = $reads->occurrence(1, 10, $receipt['condition_id'], $receipt['membership_id']);
        self::assertCount(3, $page['occurrence_photos']['rows']);
        self::assertSame('Full wheel context', $page['occurrence_photos']['rows'][0]['role']);
        self::assertTrue($page['occurrence_photos']['rows'][0]['available']);
        self::assertStringNotContainsString('damage-evidence', json_encode($page['occurrence_photos']));
        self::assertCount(1, $reads->history(1, 10, 'LF')['episode_evidence']['rows']);
        $before = $f->schema->rows();
        self::assertSame($basis, $f->member($receipt)['basis_snapshot']);
        self::assertSame($event, (int)$f->member($receipt)['condition_event_id']);
        self::assertNotNull((new VehicleDamageOccurrenceEvidenceService($db))->resolveEpisodeEvidence(1, 10, $receipt['condition_id'], $legacyId));
        self::assertSame($before, $f->schema->rows());
        $galleryCounts = [];
        $reads->gallery(1, 10, $receipt['condition_id'], $receipt['membership_id']);
        $countGallery = function () use ($reads, $receipt): int {
            $count = 0;
            $listener = static function (\CodeIgniter\Database\Query $query) use (&$count): void {
                if (preg_match('/^SELECT\b/i', ltrim($query->getQuery()))) {
                    ++$count;
                }
            };
            Events::on('DBQuery', $listener);
            try {
                $reads->gallery(1, 10, $receipt['condition_id'], $receipt['membership_id']);
            } finally {
                Events::removeListener('DBQuery', $listener);
            }return $count;
        };
        $galleryCounts[] = $countGallery();
        for ($n = 0;$n < 14;++$n) {
            $copy = $photo;
            unset($copy['id']);
            $db->table('vehicle_damage_item_evidence')->insert($copy);
        }
        $galleryCounts[] = $countGallery();
        self::assertSame([3,3], $galleryCounts, 'Metadata reads do not grow per photo.');
        $first = $reads->gallery(1, 10, $receipt['condition_id'], $receipt['membership_id']);
        self::assertCount(12, $first['rows']);
        self::assertTrue($first['has_next']);
        $second = $reads->gallery(1, 10, $receipt['condition_id'], $receipt['membership_id'], $first['next_after']);
        self::assertCount(5, $second['rows']);
        self::assertFalse($second['has_next']);
        self::assertSame([], array_intersect(array_column($first['rows'], 'id'), array_column($second['rows'], 'id')));
        $condition = $db->table('vehicle_damage_items')->where('id', $receipt['condition_id'])->get()->getRowArray();
        $alias = $this->cloneCondition($condition, ['current_condition_item_id' => $receipt['condition_id']]);
        $copy = $legacy;
        unset($copy['id']);
        $copy['vehicle_damage_item_id'] = $alias;
        $db->table('vehicle_damage_item_evidence')->insert($copy);
        $aliasEvidence = (int)$db->insertID();
        self::assertNotNull((new VehicleDamageOccurrenceEvidenceService($db))->resolveEpisodeEvidence(1, 10, $alias, $aliasEvidence));
        self::assertNull((new VehicleDamageOccurrenceEvidenceService($db))->resolveEpisodeEvidence(1, 10, $receipt['condition_id'], $aliasEvidence));
        self::assertCount(2, $reads->history(1, 10, 'LF')['episode_evidence']['rows']);
        $foreign = $this->cloneCondition($condition, ['company_id' => 2,'fleet_vehicle_id' => 20]);
        $db->table('vehicle_damage_items')->where('id', $alias)->update(['current_condition_item_id' => $foreign]);
        $this->denied(fn () => $reads->gallery(1, 10, $alias, null));
        self::assertNull((new VehicleDamageOccurrenceEvidenceService($db))->resolveEpisodeEvidence(1, 10, $alias, $aliasEvidence));
        $db->table('vehicle_damage_items')->where('id', $alias)->update(['current_condition_item_id' => $receipt['condition_id']]);
        $file = $db->table('files')->where('id', $photo['file_id'])->get()->getRowArray();
        $binary = rtrim((new \Config\Paths())->writableDirectory, '/\\').'/uploads/'.str_replace('/', DIRECTORY_SEPARATOR, $file['path']);
        unlink($binary);
        self::assertFalse($reads->gallery(1, 10, $receipt['condition_id'], $receipt['membership_id'])['rows'][0]['available']);
        $db->table('files')->where('id', $photo['file_id'])->update(['deleted_at' => '2026-10-12 10:00:00']);
        self::assertFalse($reads->history(1, 10, 'LF')['episode_evidence']['rows'][0]['available']);
        $this->denied(fn () => $reads->gallery(2, 10, $receipt['condition_id'], $receipt['membership_id']));
        $this->denied(fn () => $reads->gallery(1, 11, $receipt['condition_id'], $receipt['membership_id']));
        $this->denied(fn () => $reads->gallery(1, 10, $receipt['condition_id'], 99999));
        // Ownership is checked in the page query; a forged file association is never rendered.
        $db->table('vehicle_files')->where('file_id', $photo['file_id'])->update(['fleet_vehicle_id' => 11]);
        self::assertSame([], array_filter($reads->gallery(1, 10, $receipt['condition_id'], $receipt['membership_id'])['rows'], static fn (array $row): bool => (int)$row['file_id'] === (int)$photo['file_id']));
    }

    #[DataProvider('engines')]
    public function testFrozenGuestComparisonCorrectionsAndBoundedHistory(string $engine): void
    {
        $f = $this->fixture = new VehicleWheelDamageFixture($engine);
        $db = $f->schema->db;
        $reads = new Reads($db);
        $new = $this->success($f->service->createNew(1, 10, $f->data('LF', ['discovered_at' => '2026-09-01 10:00:00']), 7));
        $observed = $this->success($f->service->confirmUnchanged(1, 10, $f->data('LF', ['observed_at' => '2026-09-20 10:00:00','severity_code' => null,'note' => 'Frozen pre-handoff observation.','photos' => [['role' => 'full_wheel','upload' => $f->upload()]]]), 7));
        $db->table('vehicle_damage_item_events')->where('id', $observed['condition_event_id'])->update(['created_at' => '2026-09-20 10:00:00']);
        $db->table('vehicle_damage_incident_items')->where('id', $observed['membership_id'])->update(['created_at' => '2026-09-20 10:00:00']);
        $db->table('vehicle_damage_item_evidence')->where('id', $observed['evidence_ids'][0])->update(['created_at' => '2026-09-20 10:00:00']);
        $db->table('trip_movement_events')->insert(['company_id' => 1,'fleet_vehicle_id' => 10,'turo_trip_normalized_id' => 100,'event_code' => 'actual_handoff','occurred_at' => '2026-09-25 08:00:00','source' => 'operator','actor_user_id' => 7]);
        $changed = $this->success($f->service->recordWorsening(1, 10, $f->data('LF', ['trip_id' => 100,'baseline_event_id' => $observed['condition_event_id'],'baseline_membership_id' => $observed['membership_id'],'baseline_evidence_ids' => $observed['evidence_ids'],'note' => 'New delta after handoff.']), 7));
        $member = $f->member($changed);
        $snapshot = $member['basis_snapshot'];
        $comparison = $reads->occurrence(1, 10, $changed['condition_id'], $changed['membership_id']);
        self::assertSame('Observed before this guest', $comparison['comparison']['heading']);
        self::assertSame('Frozen pre-handoff observation.', $comparison['comparison']['before']);
        $this->success((new VehicleDamageService($db))->correctDetails(1, 10, $changed['condition_id'], ['zone_code' => 'wheel_tire','damage_type_code' => 'wheel_curb_rash','panel_code' => 'front_right_wheel_tire','description' => 'Today corrected wheel text.','note' => 'Synthetic identity correction.'], 7));
        self::assertSame('Frozen pre-handoff observation.', $reads->occurrence(1, 10, $changed['condition_id'], $changed['membership_id'])['comparison']['before']);
        self::assertSame($snapshot, $f->member($changed)['basis_snapshot']);
        $correctedProjection = $reads->forVehicles(1, [10], [10 => 100])[10]['wheels']['RF'];
        self::assertSame($changed['membership_id'], (int)$correctedProjection['latest_physical_occurrence']['id']);
        self::assertSame('Worsened on this trip', $correctedProjection['trip_badge']);
        self::assertSame('front_left_wheel_tire', $correctedProjection['latest_physical_occurrence']['panel_code']);
        self::assertCount(0, $reads->ledger(1, 10, ['wheel' => 'LF','identity' => 'current'])['results']);
        self::assertCount(1, $reads->ledger(1, 10, ['wheel' => 'RF','identity' => 'current'])['results']);
        self::assertCount(3, $reads->ledger(1, 10, ['wheel' => 'LF','identity' => 'recorded'])['results']);
        $incidentRepo = new \App\Repositories\VehicleDamageIncidentRepository($db);
        $incident = $incidentRepo->incident(1, 10, $changed['incident_id']);
        $this->success((new \App\Services\Fleet\VehicleDamageIncidentService($db))->attributeTrip(1, 10, $changed['incident_id'], ['expected_state' => \App\Services\Fleet\VehicleDamageIncidentService::fingerprint($incident),'trip_id' => 102,'attribution_type' => 'suspected_cause','reason' => 'Synthetic later attribution correction.'], 7));
        self::assertSame('Last recorded condition', $reads->occurrence(1, 10, $changed['condition_id'], $changed['membership_id'])['comparison']['heading']);
        self::assertSame($snapshot, $f->member($changed)['basis_snapshot']);
        self::assertContains('attribution_corrected', array_column($reads->history(1, 10, 'RF')['timeline'], 'effect'));
        $original = $db->table('vehicle_damage_items')->where('id', $changed['condition_id'])->get()->getRowArray();
        for ($n = 0;$n < 28;++$n) {
            $copy = $original;
            unset($copy['id']);
            $copy['status_code'] = 'resolved_other';
            $copy['discovered_at'] = sprintf('2026-08-%02d 10:00:00', $n + 1);
            $this->cloneCondition($copy);
        }
        $history = $reads->history(1, 10, 'RF');
        self::assertCount(25, $history['episodes']);
        self::assertTrue($history['episode_has_next']);
        self::assertCount(4, $reads->history(1, 10, 'RF', 2)['episodes']);
        $member = $db->table('vehicle_damage_incident_items')->where('id', $changed['membership_id'])->get()->getRowArray();
        $incident = $db->table('vehicle_damage_incidents')->where('id', $changed['incident_id'])->get()->getRowArray();
        for ($n = 0;$n < 28;++$n) {
            $parent = $incident;
            unset($parent['id']);
            $db->table('vehicle_damage_incidents')->insert($parent);
            $copy = $member;
            unset($copy['id']);
            $copy['vehicle_damage_incident_id'] = (int)$db->insertID();
            $copy['condition_event_id'] = null;
            $copy['basis_snapshot'] = null;
            $db->table('vehicle_damage_incident_items')->insert($copy);
        }
        $history = $reads->history(1, 10, 'RF');
        self::assertCount(25, $history['timeline']);
        self::assertTrue($history['timeline_has_next']);
        self::assertNotEmpty($reads->history(1, 10, 'RF', 1, null, 2)['timeline']);
        $filters = ['wheel' => 'RF','damage_type' => 'wheel_curb_rash','physical_status' => 'resolved','identity' => 'current','q' => 'corrected'];
        $ledger = $reads->ledger(1, 10, $filters);
        self::assertCount(25, $ledger['results']);
        self::assertTrue($ledger['has_next']);
        self::assertSame($filters, $ledger['filters']);
        self::assertCount(3, $reads->ledger(1, 10, $filters, 2)['results']);
        // Future and historical trip contexts cannot reuse another trip's current badge.
        $trip = $db->table('turo_trips_normalized')->where('id', 100)->get()->getRowArray();
        $trip['id'] = 103;
        $trip['turo_trip_id'] = 'SYNTHETIC-FUTURE-103';
        $trip['turo_reservation_id'] = 'SYNTHETIC-FUTURE-103';
        $trip['starts_at'] = '2027-01-01 10:00:00';
        $db->table('turo_trips_normalized')->insert($trip);
        self::assertNull($reads->forVehicles(1, [10], [10 => 103])[10]['wheels']['RF']['trip_badge']);
        $before = $f->schema->rows();
        foreach (['LF','RF','UNKNOWN'] as $wheel) {
            $reads->history(1, 10, $wheel);
        }self::assertSame($before, $f->schema->rows());
    }

    #[DataProvider('engines')]
    public function testFixedBulkQueryBudgetAndLargeHistoryPlans(string $engine): void
    {
        $f = $this->fixture = new VehicleWheelDamageFixture($engine);
        $db = $f->schema->db;
        $receipt = $this->success($f->service->createNew(1, 10, $f->data(), 7));
        $prototype = $db->table('vehicle_damage_items')->where('id', $receipt['condition_id'])->get()->getRowArray();
        $event = $db->table('vehicle_damage_item_events')->where('id', $receipt['condition_event_id'])->get()->getRowArray();
        $incident = $db->table('vehicle_damage_incidents')->where('id', $receipt['incident_id'])->get()->getRowArray();
        $membership = $db->table('vehicle_damage_incident_items')->where('id', $receipt['membership_id'])->get()->getRowArray();
        $vehicles = [];
        $conditions = [];
        $events = [];
        $incidents = [];
        $members = [];
        $jobs = [];
        $work = [];
        for ($index = 0;$index < 100;++$index) {
            $vehicle = 1000 + $index;
            $vehicles[] = $vehicle;
            $db->table('fleet_vehicles')->insert(['id' => $vehicle,'company_id' => 1,'vehicle_spec_id' => 1,'vehicle_trim_level_id' => 1,'vehicle_drivetrain_id' => 1,'vehicle_status_id' => 1,'fleet_code' => 'WHEEL-READ-'.$vehicle,'display_name' => 'Synthetic bulk wheel '.$vehicle]);
            foreach (VehicleWheelIdentity::choices() as $corner => $identity) {
                $id = 10000 + $index * 4 + array_search($corner, array_keys(VehicleWheelIdentity::choices()), true);
                $condition = array_replace($prototype, ['id' => $id,'fleet_vehicle_id' => $vehicle,'panel_code' => $identity['panel_code']]);
                $conditions[] = $condition;
                for ($n = 0;$n < 20;++$n) {
                    $eventId = 20000 + ($id - 10000) * 20 + $n;
                    $incidentId = 30000 + ($id - 10000) * 20 + $n;
                    $time = sprintf('2026-10-01 10:%02d:00', $n);
                    $effect = $n === 0 ? 'new_damage' : ($n === 19 ? 'observed_existing' : 'worsened');
                    $events[] = array_replace($event, ['id' => $eventId,'vehicle_damage_item_id' => $id,'event_code' => $n === 0 ? 'created' : $effect,'occurred_at' => $time,'created_at' => $time]);
                    $incidents[] = array_replace($incident, ['id' => $incidentId,'fleet_vehicle_id' => $vehicle,'discovered_at' => $time]);
                    $members[] = array_replace($membership, ['id' => $incidentId,'vehicle_damage_incident_id' => $incidentId,'vehicle_damage_item_id' => $id,'panel_code' => $identity['panel_code'],'effect_code' => $effect,'condition_event_id' => $eventId,'basis_snapshot' => null,'created_at' => $time]);
                }
            }
        }
        // Substantial work history, plus unknown and conflicting records, must not add reads.
        foreach ($vehicles as $index => $vehicle) {
            $unknown = array_replace($prototype, ['id' => 50000 + $index,'fleet_vehicle_id' => $vehicle,'panel_code' => null]);
            $conditions[] = $unknown;
            $duplicate = array_replace($prototype, ['id' => 51000 + $index,'fleet_vehicle_id' => $vehicle,'panel_code' => VehicleWheelIdentity::panel('LR')]);
            $conditions[] = $duplicate;
            for ($n = 0;$n < 30;++$n) {
                $jobId = 60000 + $index * 30 + $n;
                $jobs[] = ['id' => $jobId,'company_id' => 1,'fleet_vehicle_id' => $vehicle,'intent_code' => 'repair','category_code' => 'wheel','summary' => 'Synthetic historical work','status_code' => 'completed','creation_command_key' => sprintf('10000000-0000-4000-8000-%012d', $jobId),'creation_command_payload_hash' => str_repeat('a', 64),'created_by' => 7,'updated_by' => 7,'created_at' => '2026-10-05 10:00:00','updated_at' => '2026-10-05 10:00:00'];
                for ($corner = 0;$corner < 4;++$corner) {
                    $work[] = ['company_id' => 1,'vehicle_damage_repair_job_id' => $jobId,'vehicle_damage_item_id' => 10000 + $index * 4 + $corner,'result_code' => 'unchanged','created_by' => 7,'updated_by' => 7,'created_at' => '2026-10-05 10:00:00','updated_at' => '2026-10-05 10:00:00'];
                }
            }
        }
        foreach (['vehicle_damage_items' => $conditions,'vehicle_damage_item_events' => $events,'vehicle_damage_incidents' => $incidents,'vehicle_damage_incident_items' => $members,'vehicle_damage_repair_jobs' => $jobs,'vehicle_damage_repair_job_items' => $work] as $table => $rows) {
            foreach (array_chunk($rows, 250) as $chunk) {
                $db->table($table)->insertBatch($chunk);
            }
        }
        if ($engine === 'maria') {
            foreach (['vehicle_damage_items','vehicle_damage_item_events','vehicle_damage_incident_items','vehicle_damage_incidents','vehicle_damage_repair_jobs','vehicle_damage_repair_job_items'] as $table) {
                $db->query('ANALYZE TABLE '.$db->escapeIdentifiers($db->prefixTable($table)));
            }
        }
        $reads = new Reads($db);
        $reads->forVehicles(1, [$vehicles[0]]);
        $counts = [];
        foreach ([1,10,100] as $size) {
            $sql = [];
            $listener = static function (\CodeIgniter\Database\Query $query) use (&$sql): void {
                if (preg_match('/^SELECT\b/i', ltrim($query->getQuery()))) {
                    $sql[] = $query->getQuery();
                }
            };
            Events::on('DBQuery', $listener);
            try {
                $projection = $reads->forVehicles(1, array_slice($vehicles, 0, $size));
            } finally {
                Events::removeListener('DBQuery', $listener);
            }self::assertCount($size, $projection);
            self::assertCount(3, $sql);
            $counts[$size] = count($sql);
            foreach ($projection as $vehicle) {
                self::assertSame(['LF','RF','RR','LR'], array_keys($vehicle['wheels']));
                self::assertSame(1, $vehicle['unknown']['count']);
                foreach ($vehicle['wheels'] as $code => $wheel) {
                    if ($code === 'LR') {
                        self::assertSame('Review conflicting records', $wheel['status']);
                        self::assertNull($wheel['active_condition_id']);
                        continue;
                    }self::assertSame('Open rash', $wheel['status']);
                    self::assertSame('unchanged', $wheel['repair_context']['result_code']);
                    self::assertSame('observed_existing', $wheel['latest_observation']['effect_code']);
                    self::assertSame('worsened', $wheel['latest_physical_occurrence']['effect_code']);
                }
            }
        }
        $repo = new VehicleWheelReadRepository($db);
        $plans = [];
        foreach ($repo->projectionQueries(1, $vehicles, []) as $sql) {
            $plan = $repo->rows(($engine === 'maria' ? 'EXPLAIN ' : 'EXPLAIN QUERY PLAN ').$sql);
            self::assertNotEmpty($plan);
            $plans[] = $plan;
        }
        if ($engine === 'maria') {
            self::assertStringContainsString('damage_event_latest_idx', json_encode($plans));
            self::assertStringContainsString('damage_membership_latest_idx', json_encode($plans));
            $latestPlan = array_find($plans[0], static fn (array $row): bool => $row['table'] === 'le');
            self::assertSame('damage_event_latest_idx', $latestPlan['key']);
            $membershipPlan = array_find($plans[1], static fn (array $row): bool => $row['table'] === 'm');
            self::assertContains($membershipPlan['type'], ['ref','eq_ref']);
            self::assertContains($membershipPlan['key'], ['damage_membership_latest_idx','damage_membership_vehicle_damage_item_id_fk','vehicle_damage_incident_id_vehicle_damage_item_id']);
        }
        $directory = dirname(__DIR__, 2).'/build/slice4/';
        if (!is_dir($directory)) {
            mkdir($directory, 0700, true);
        }file_put_contents($directory.'query-budget-'.$engine.'.json', json_encode(['counts' => $counts,'vehicles' => 100,'conditions' => 600,'memberships' => 8000,'jobs' => 3000,'repair_memberships' => 12000,'plans' => $plans], JSON_PRETTY_PRINT));
        $many = $reads->history(1, $vehicles[0], 'LF');
        self::assertCount(20, $many['timeline']);
        self::assertFalse($many['timeline_has_next']);
        self::assertCount(25, $many['repairs']);
        self::assertTrue($many['repair_has_next']);
        self::assertCount(5, $reads->history(1, $vehicles[0], 'LF', 1, null, 1, 2)['repairs']);
    }
}
