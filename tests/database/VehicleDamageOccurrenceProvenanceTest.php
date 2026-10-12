<?php

namespace Tests\Database;

use App\Repositories\VehicleDamageIncidentRepository;
use App\Repositories\VehicleDamageOccurrenceRepository;
use App\Repositories\VehicleDamageRepository;
use App\Services\Fleet\VehicleDamageIncidentService;
use App\Services\Fleet\VehicleDamageOccurrenceBasisSnapshot as Snapshot;
use App\Services\Fleet\VehicleDamageService;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\VehicleDamageOccurrenceFixture as Fixture;

/** @internal Provenance/security on both engines without wheel episode behavior. */
final class VehicleDamageOccurrenceProvenanceTest extends CIUnitTestCase
{
    public static function engines(): array
    {
        return [['sqlite'], ['maria']];
    }

    #[DataProvider('engines')]
    public function testGenericUnknownIdentityKeepsNullProvenanceWithoutClassification(string $engine): void
    {
        $fixture = new Fixture($engine);
        try {
            $fixture->migrate();
            $db = $fixture->db;
            $created = (new VehicleDamageService($db))->create(1, 10, ['zone_code' => 'wheel_tire', 'damage_type_code' => 'wheel_curb_rash', 'severity_code' => 'cosmetic', 'description' => 'Synthetic wheel position unknown', 'discovered_at' => '2026-10-01 10:00:00'], 7);
            $this->assertTrue($created['success']);
            $this->assertGreaterThan(0, $created['condition_event_id']);
            $before = $fixture->rows();
            $mismatch = (new VehicleDamageIncidentService($db))->create(1, 10, Fixture::incident(['areas' => [Fixture::area(['effect_code' => 'worsened', 'vehicle_damage_item_id' => $created['id']])]]), 7);
            $this->assertFalse($mismatch['success'], 'Unknown wheel cannot be silently classified by an occurrence.');
            $this->assertSame($before, $fixture->rows());
            $generic = (new VehicleDamageService($db))->create(1, 10, ['zone_code' => 'wheel_tire', 'damage_type_code' => 'scratch_scuff', 'severity_code' => 'cosmetic', 'description' => 'Synthetic generic position unknown', 'discovered_at' => '2026-10-01 10:00:00'], 7);
            $this->assertTrue($generic['success']);
            $incident = $fixture->create(['areas' => [Fixture::area(['damage_type_code' => 'scratch_scuff', 'effect_code' => 'worsened', 'vehicle_damage_item_id' => $generic['id'], 'external_reference' => 'Synthetic occurrence-specific reference'])]]);
            $member = (new VehicleDamageIncidentRepository($db))->memberships(1, 10, $incident)[0];
            $this->assertNull($member['condition_event_id'], 'Incomplete prior identity is not invented into exact event provenance.');
            $this->assertNull($member['basis_snapshot']);
            $this->assertNull((new VehicleDamageRepository($db))->item(1, 10, (int) $created['id'])['panel_code']);
            $this->assertCount(1, (new VehicleDamageOccurrenceRepository($db))->evidence(1, 10, (int) $member['id']));
        } finally {
            $fixture->close();
        }
    }

    #[DataProvider('engines')]
    public function testSnapshotRejectsUnrelatedEventsMembershipsEvidenceAndTrip(string $engine): void
    {
        $fixture = new Fixture($engine);
        try {
            $fixture->migrate();
            $db = $fixture->db;
            $a = $fixture->create(['areas' => [Fixture::area(['file_id' => 800])]]);
            $b = $fixture->create(['areas' => [Fixture::area(['panel_code' => 'front_right_wheel_tire', 'image_id' => 900])]]);
            $incidents = new VehicleDamageIncidentRepository($db);
            $first = $incidents->memberships(1, 10, $a)[0];
            $second = $incidents->memberships(1, 10, $b)[0];
            $occurrences = new VehicleDamageOccurrenceRepository($db);
            $firstPhoto = $occurrences->evidence(1, 10, (int) $first['id'])[0];
            $secondPhoto = $occurrences->evidence(1, 10, (int) $second['id'])[0];
            $helper = new Snapshot($db);
            $db->transBegin();
            $snapshot = $helper->build(1, 10, 'front_left_wheel_tire', 'wheel_curb_rash', date('Y-m-d H:i:s'), (int) $first['vehicle_damage_item_id'], (int) $first['id'], null, (int) $first['condition_event_id'], (int) $first['id'], [(int) $firstPhoto['id']]);
            $db->transCommit();
            $cases = [];
            foreach ([['condition_event_id', (int) $second['condition_event_id']], ['incident_item_id', (int) $second['id']], ['evidence_ids', [(int) $secondPhoto['id']]], ['kind', 'observed_existing']] as [$key, $value]) {
                $case = $snapshot;
                $case['comparison']['baseline'][$key] = $value;
                $cases[] = $case;
            }
            $case = $snapshot;
            $case['comparison']['trip_id'] = 101;
            $cases[] = $case;
            $case = $snapshot;
            $case['prior_condition']['latest_condition_event_id'] = (int) $second['condition_event_id'];
            $cases[] = $case;
            foreach ($cases as $case) {
                try {
                    $helper->validateForWrite(1, 10, $case);
                    $this->fail('Unrelated or malformed snapshot references must reject.');
                } catch (\InvalidArgumentException $error) {
                    $this->assertNotSame('', $error->getMessage());
                }
            }
            $late = (new VehicleDamageService($db))->attachEvidence(1, 10, (int) $first['vehicle_damage_item_id'], ['external_reference' => 'Synthetic forged request link', 'vehicle_damage_incident_item_id' => $first['id']], 7);
            $this->assertTrue($late['success']);
            $rows = (new VehicleDamageRepository($db))->evidence(1, (int) $first['vehicle_damage_item_id']);
            $this->assertNull($rows[count($rows) - 1]['vehicle_damage_incident_item_id'], 'Raw POST child ID is not attachment authority.');
        } finally {
            $fixture->close();
        }
    }

    #[DataProvider('engines')]
    public function testExactInsertedEventsLegacyEvidenceAndLateAttachment(string $engine): void
    {
        $fixture = new Fixture($engine);
        try {
            $legacyIncident = $fixture->createLegacy(['areas' => [Fixture::area(['file_id' => 800])]]);
            $legacyEvidence = (new VehicleDamageRepository($fixture->db))->evidence(1, 1);
            $fixture->migrate();
            $db = $fixture->db;
            $repo = new VehicleDamageIncidentRepository($db);
            $legacy = $repo->memberships(1, 10, $legacyIncident)[0];
            $this->assertNull($legacy['condition_event_id']);
            $this->assertNull($legacy['basis_snapshot']);
            $currentLegacyEvidence = (new VehicleDamageRepository($db))->evidence(1, (int) $legacy['vehicle_damage_item_id']);
            $this->assertNull($currentLegacyEvidence[0]['vehicle_damage_incident_item_id']);
            unset($currentLegacyEvidence[0]['vehicle_damage_incident_item_id']);
            $this->assertSame($legacyEvidence, $currentLegacyEvidence);
            $incident = $fixture->create(['areas' => [Fixture::area(['file_id' => 800])], 'basis_snapshot' => '{"forged":true}']);
            $created = $repo->memberships(1, 10, $incident)[0];
            $item = (int) $created['vehicle_damage_item_id'];
            $occurrences = new VehicleDamageOccurrenceRepository($db);
            $this->assertSame('created', $occurrences->event(1, 10, $item, (int) $created['condition_event_id'])['event_code']);
            $this->assertNull($created['basis_snapshot'], 'Generic input cannot provide snapshot authority.');
            $this->assertCount(1, $occurrences->evidence(1, 10, (int) $created['id']));
            foreach (['worsened', 'observed_existing'] as $effect) {
                $next = $fixture->create(['areas' => [Fixture::area(['effect_code' => $effect, 'vehicle_damage_item_id' => $item, 'note' => 'Synthetic occurrence delta', 'image_id' => 900])]]);
                $member = $repo->memberships(1, 10, $next)[0];
                $this->assertSame($effect, $occurrences->event(1, 10, $item, (int) $member['condition_event_id'])['event_code']);
                $this->assertCount(1, $occurrences->evidence(1, 10, (int) $member['id']));
            }
            $before = $occurrences->membership(1, 10, (int) $member['id']);
            $result = (new VehicleDamageService($db))->attachEvidence(1, 10, $item, ['external_reference' => 'Synthetic later close-up'], 7, (int) $member['id']);
            $this->assertTrue($result['success'], json_encode($result));
            $this->assertSame($before, $occurrences->membership(1, 10, (int) $member['id']));
            $this->assertCount(2, $occurrences->evidence(1, 10, (int) $member['id']));
            $this->assertNull($occurrences->membership(2, 10, (int) $member['id']));
            $this->assertSame([], $occurrences->evidence(1, 11, (int) $member['id']));
            $beforeRows = $fixture->rows();
            $occurrences->membership(1, 10, (int) $member['id']);
            $occurrences->evidence(1, 10, (int) $member['id']);
            Snapshot::read($member['basis_snapshot']);
            $this->assertSame($beforeRows, $fixture->rows(), 'Read helpers have zero business writes.');
        } finally {
            $fixture->close();
        }
    }

    #[DataProvider('engines')]
    public function testSnapshotConstructionOwnershipChronologyAndFrozenHistory(string $engine): void
    {
        $fixture = new Fixture($engine);
        try {
            $fixture->migrate();
            $db = $fixture->db;
            $id = $fixture->create();
            $repo = new VehicleDamageIncidentRepository($db);
            $member = $repo->memberships(1, 10, $id)[0];
            $item = (int) $member['vehicle_damage_item_id'];
            $observation = $fixture->create(['areas' => [Fixture::area(['effect_code' => 'observed_existing', 'vehicle_damage_item_id' => $item, 'file_id' => 800])]]);
            $observed = $repo->memberships(1, 10, $observation)[0];
            $occurrences = new VehicleDamageOccurrenceRepository($db);
            $evidence = $occurrences->evidence(1, 10, (int) $observed['id'])[0];
            $snapshotHelper = new Snapshot($db);
            $db->transBegin();
            $snapshot = $snapshotHelper->build(1, 10, 'front_left_wheel_tire', 'wheel_curb_rash', date('Y-m-d H:i:s'), $item, (int) $observed['id'], null, (int) $observed['condition_event_id'], (int) $observed['id'], [(int) $evidence['id']]);
            $json = $snapshotHelper->validateForWrite(1, 10, $snapshot);
            $this->assertNull($snapshot['prior_occurrence']['occurred_at'], 'Unknown actual occurrence is not replaced by recording time.');
            $this->assertSame('observed_existing', $snapshot['comparison']['baseline']['kind']);
            $next = $fixture->create(['areas' => [Fixture::area(['effect_code' => 'worsened', 'vehicle_damage_item_id' => $item, 'severity_code' => 'moderate', 'note' => 'Synthetic later worsening'])]]);
            $nextMember = $repo->memberships(1, 10, $next)[0];
            $values = array_intersect_key($nextMember, array_flip(['company_id', 'vehicle_damage_incident_id', 'vehicle_damage_item_id', 'effect_code', 'panel_code', 'damage_type_code', 'severity_code', 'note', 'created_by', 'created_at', 'condition_event_id']));
            // Validate the insert contract before storing the frozen basis on the already-created synthetic row.
            $occurrences->validateMembership($values + ['basis_snapshot' => $json]);
            $db->table('vehicle_damage_incident_items')->where('id', $nextMember['id'])->update(['basis_snapshot' => $json]);
            $db->transCommit();
            $stored = $occurrences->membership(1, 10, (int) $nextMember['id']);
            $this->assertSame('cosmetic', Snapshot::read($stored['basis_snapshot'])['snapshot']['prior_condition']['severity_code']);
            $this->assertSame('moderate', (new VehicleDamageRepository($db))->item(1, 10, $item)['severity_code']);
            $late = (new VehicleDamageService($db))->attachEvidence(1, 10, $item, ['external_reference' => 'Synthetic late evidence'], 7, (int) $nextMember['id']);
            $this->assertTrue($late['success']);
            $this->assertSame($stored, $occurrences->membership(1, 10, (int) $nextMember['id']));
            foreach ([['panel_code' => 'front_right_wheel_tire'], ['damage_type_code' => 'dent']] as $change) {
                try {
                    $snapshotHelper->validateForWrite(1, 10, array_replace($snapshot, $change));
                    $this->fail('Mismatched basis identity must reject.');
                } catch (\InvalidArgumentException $error) {
                    $this->assertStringContainsString('panel and damage type', $error->getMessage());
                }
            }
            foreach ([[2, 10], [1, 11], [1, 20]] as [$company, $vehicle]) {
                try {
                    $snapshotHelper->validateForWrite($company, $vehicle, $snapshot);
                    $this->fail('Cross-owner basis must reject.');
                } catch (\InvalidArgumentException $error) {
                    $this->assertNotSame('', $error->getMessage());
                }
            }
        } finally {
            $fixture->close();
        }
    }

    #[DataProvider('engines')]
    public function testMismatchedEventsEvidenceAndAdministrativeAliases(string $engine): void
    {
        $fixture = new Fixture($engine);
        try {
            $fixture->migrate();
            $db = $fixture->db;
            $a = $fixture->create(['areas' => [Fixture::area(['damage_type_code' => 'scratch_scuff'])]]);
            $b = $fixture->create(['areas' => [Fixture::area(['file_id' => 800, 'damage_type_code' => 'scratch_scuff'])]]);
            $repo = new VehicleDamageIncidentRepository($db);
            $target = $repo->memberships(1, 10, $a)[0];
            $source = $repo->memberships(1, 10, $b)[0];
            $occurrences = new VehicleDamageOccurrenceRepository($db);
            $values = array_intersect_key($source, array_flip(['company_id', 'vehicle_damage_incident_id', 'vehicle_damage_item_id', 'effect_code', 'panel_code', 'damage_type_code', 'severity_code', 'note', 'created_by', 'created_at', 'condition_event_id']));
            foreach ([['effect_code' => 'worsened'], ['condition_event_id' => $target['condition_event_id']], ['company_id' => 2], ['vehicle_damage_item_id' => $target['vehicle_damage_item_id']]] as $change) {
                try {
                    $repo->insertMembership(array_replace($values, $change));
                    $this->fail('Mismatched provenance must reject before insert.');
                } catch (\InvalidArgumentException $error) {
                    $this->assertNotSame('', $error->getMessage());
                }
            }
            $bad = (new VehicleDamageService($db))->attachEvidence(1, 10, (int) $source['vehicle_damage_item_id'], ['file_id' => 800], 7, (int) $target['id']);
            $this->assertFalse($bad['success']);
            $items = new VehicleDamageRepository($db);
            $sourceItem = $items->item(1, 10, (int) $source['vehicle_damage_item_id']);
            $targetItem = $items->item(1, 10, (int) $target['vehicle_damage_item_id']);
            $beforeEvidence = $items->evidence(1, (int) $sourceItem['id']);
            $link = (new VehicleDamageIncidentService($db))->linkHistorical(1, 10, (int) $sourceItem['id'], (int) $targetItem['id'], ['confirmed' => '1', 'reason' => 'Synthetic historical provenance review', 'source_state' => VehicleDamageIncidentService::fingerprint($sourceItem), 'target_state' => VehicleDamageIncidentService::fingerprint($targetItem)], 7);
            $this->assertTrue($link['success'], json_encode($link));
            foreach ($repo->memberships(1, 10, (int) $link['id']) as $administrative) {
                $this->assertNull($administrative['condition_event_id']);
                $this->assertNull($administrative['basis_snapshot']);
            }
            $this->assertSame($beforeEvidence, $items->evidence(1, (int) $sourceItem['id']));
            $allowed = (new VehicleDamageService($db))->attachEvidence(1, 10, (int) $sourceItem['id'], ['external_reference' => 'Synthetic one-hop original-parent attachment'], 7, (int) $target['id']);
            $this->assertTrue($allowed['success'], json_encode($allowed));
            $linked = $occurrences->evidence(1, 10, (int) $target['id']);
            $this->assertSame((int) $sourceItem['id'], (int) $linked[0]['vehicle_damage_item_id']);
            $before = $fixture->rows();
            $failed = (new VehicleDamageIncidentService($db))->create(1, 10, Fixture::incident(['areas' => [Fixture::area(), Fixture::area(['panel_code' => 'front_right_wheel_tire', 'file_id' => 801])]]), 7);
            $this->assertFalse($failed['success']);
            $this->assertSame($before, $fixture->rows(), 'Failed occurrence/evidence rolls back all rows and audits.');
        } finally {
            $fixture->close();
        }
    }
}
