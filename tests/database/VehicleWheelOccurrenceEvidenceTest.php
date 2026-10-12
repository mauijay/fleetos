<?php

namespace Tests\Database;

use App\Repositories\VehicleDamageRepository;
use App\Services\Files\PrivateEvidenceStorageService;
use App\Services\Fleet\VehicleDamageOccurrenceEvidenceService as Evidence;
use App\Services\Fleet\VehicleWheelDamageService;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\VehicleWheelDamageFixture;

final class VehicleWheelOccurrenceEvidenceTest extends CIUnitTestCase
{
    private ?VehicleWheelDamageFixture $fixture = null;

    protected function tearDown(): void
    {
        $this->fixture?->close();
        parent::tearDown();
    }

    public static function cases(): iterable
    {
        foreach (['sqlite', 'maria'] as $engine) {
            foreach (['formats', 'late', 'security', 'rollback', 'storage_failure', 'uncertain', 'limits', 'alias', 'existing_photos', 'rollback_worsening'] as $case) {
                yield $engine . '-' . $case => [$engine, $case];
            }
        }
    }

    #[DataProvider('cases')]
    public function testPrivateOccurrenceEvidence(string $engine, string $case): void
    {
        $f = $this->fixture = new VehicleWheelDamageFixture($engine);
        $db = $f->schema->db;
        $evidence = new Evidence($db);
        $data = $f->data();
        $data['photos'] = [['role' => 'full_wheel', 'upload' => $f->upload()]];
        if (in_array($case, ['rollback', 'storage_failure', 'rollback_worsening'], true)) {
            if ($case === 'rollback_worsening') {
                self::assertTrue($f->service->createNew(1, 10, $f->data(), 7)['success']);
                $data = $f->data('LF', ['photos' => $data['photos']]);
            }
            $before = $f->schema->rows();
            $paths = [];
            $storage = new class ($paths, $case, new \App\Repositories\FileRepository($db)) extends PrivateEvidenceStorageService {
                public function __construct(private array &$paths, private string $fault, \App\Repositories\FileRepository $files)
                {
                    parent::__construct($files);
                }
                public function paths(): array
                {
                    return $this->paths;
                }
                public function store(\CodeIgniter\HTTP\Files\UploadedFile $upload, string $storageDirectory, array $allowedMimeTypes, int $maxFileSizeBytes, ?string $documentDate = null, ?int $uploadedBy = null, bool $private = false, ?int $candidateId = null): array
                {
                    if ($this->fault === 'storage_failure') {
                        throw new \RuntimeException('Synthetic file write failure after occurrence DB staging.');
                    }
                    $stored = parent::store($upload, $storageDirectory, $allowedMimeTypes, $maxFileSizeBytes, $documentDate, $uploadedBy, $private, $candidateId);
                    $this->paths[] = $stored['absolute_path'];
                    return $stored;
                }
            };
            $writer = new class ($db, $storage) extends Evidence {
                public function attach(int $company, int $vehicle, int $condition, int $membership, array $photos, int $actor): array
                {
                    parent::attach($company, $vehicle, $condition, $membership, $photos, $actor);
                    throw new \RuntimeException('Synthetic DB staging failure after successful file write.');
                }
            };
            $method = $case === 'rollback_worsening' ? 'recordWorsening' : 'createNew';
            $result = (new VehicleWheelDamageService($db, $writer))->{$method}(1, 10, $data, 7);
            self::assertFalse($result['success'], json_encode($result));
            self::assertSame($before, $f->schema->rows());
            self::assertSame($paths, $storage->paths());
            self::assertCount($case === 'storage_failure' ? 0 : 1, $paths);
            foreach ($paths as $path) {
                self::assertFileDoesNotExist($path);
            }
            return;
        }
        if ($case === 'uncertain') {
            $service = new class ($db) extends VehicleWheelDamageService {
                protected function commit(): void
                {
                    parent::commit();
                    throw new \RuntimeException('Synthetic lost acknowledgement after COMMIT.');
                }
            };
            $result = $service->createNew(1, 10, $data, 7);
            self::assertTrue($result['uncertain'], json_encode($result));
            self::assertArrayNotHasKey('upload', $result['retry_payload']['photos'][0]);
            $before = $f->schema->rows();
            $recovered = $f->service->createNew(1, 10, $result['retry_payload'], 7);
            self::assertTrue($recovered['success'], json_encode($recovered));
            self::assertTrue($recovered['replayed']);
            self::assertSame($before, $f->schema->rows());
            self::assertNotNull($evidence->resolve(1, 10, $recovered['condition_id'], $recovered['membership_id'], $recovered['evidence_ids'][0]));
            return;
        }
        if ($case === 'formats') {
            $data['photos'] = [];
            foreach (['jpg', 'png', 'webp'] as $format) {
                $upload = $f->upload($format, 'misleading.' . ($format === 'jpg' ? 'heic' : 'jpg'));
                if ($format === 'png') {
                    $upload = new \CodeIgniter\HTTP\Files\UploadedFile($upload->getTempName(), $upload->getClientName(), 'application/pdf', 1, UPLOAD_ERR_OK);
                }
                $data['photos'][] = ['role' => 'close_up', 'upload' => $upload];
            }
        }
        $normalizedPhotos = $evidence->normalize($data['photos']);
        $receipt = $f->service->createNew(1, 10, $data, 7);
        self::assertTrue($receipt['success'], json_encode($receipt));
        $condition = $receipt['condition_id'];
        $membership = $receipt['membership_id'];
        $photo = $receipt['evidence_ids'][0];
        $resolved = $evidence->resolve(1, 10, $condition, $membership, $photo);
        self::assertNotNull($resolved);
        self::assertStringContainsString('damage-evidence', $resolved['path']);
        self::assertStringNotContainsString('public', $resolved['path']);
        if (DIRECTORY_SEPARATOR === '/') {
            self::assertSame(0600, fileperms($resolved['path']) & 0777);
            self::assertSame(0700, fileperms(dirname($resolved['path'])) & 0777);
        }
        if ($case === 'formats') {
            self::assertCount(3, $receipt['evidence_ids']);
            foreach ($receipt['evidence_ids'] as $id) {
                $image = $evidence->resolve(1, 10, $condition, $membership, $id);
                self::assertNotNull($image);
                self::assertSame(filesize($image['path']), $image['metadata']['size_bytes']);
            }
            $descriptors = $normalizedPhotos;
            $before = $f->schema->rows();
            $replay = $f->service->createNew(1, 10, array_replace($data, ['photos' => array_map(static fn (array $p): array => ['role' => $p['role'], 'descriptor' => $p['descriptor']], $descriptors)]), 7);
            self::assertTrue($replay['replayed'], json_encode($replay));
            self::assertSame($before, $f->schema->rows());
            $audit = $db->table('audit_logs')->like('new_values', $data['command_uuid'])->get()->getRowArray();
            $invalid = json_decode($audit['new_values'], true);
            $invalid['wheel_command']['evidence_descriptors'][0]['width'] = 'invalid';
            $db->table('audit_logs')->where('id', $audit['id'])->update(['new_values' => json_encode($invalid)]);
            $invalidBefore = $f->schema->rows();
            self::assertFalse($f->service->createNew(1, 10, array_replace($data, ['photos' => array_map(static fn (array $p): array => ['role' => $p['role'], 'descriptor' => $p['descriptor']], $descriptors)]), 7)['success']);
            self::assertSame($invalidBefore, $f->schema->rows());
        } elseif ($case === 'existing_photos') {
            foreach (['recordWorsening', 'confirmUnchanged'] as $method) {
                $next = $f->data('LF', ['observed_at' => '2026-10-02 10:19:00', 'photos' => [['role' => 'close_up', 'upload' => $f->upload('jpg')]]]);
                $result = $f->service->{$method}(1, 10, $next, 7);
                self::assertTrue($result['success'], json_encode($result));
                self::assertSame($condition, $result['condition_id']);
                self::assertCount(1, $result['evidence_ids']);
                self::assertSame($result['condition_event_id'], (int) $f->member($result)['condition_event_id']);
                self::assertNotNull($evidence->resolve(1, 10, $condition, $result['membership_id'], $result['evidence_ids'][0]));
            }
        } elseif ($case === 'late') {
            $before = $f->member($receipt);
            $events = $db->table('vehicle_damage_item_events')->get()->getResultArray();
            $late = ['command_uuid' => \App\Services\Fleet\VehicleDamageRepairService::commandKey(), 'photos' => [['role' => 'close_up', 'upload' => $f->upload('jpg')]]];
            $normalized = $evidence->normalize($late['photos']);
            $attached = $f->service->attachPhotos(1, 10, $condition, $membership, $late, 7);
            self::assertTrue($attached['success'], json_encode($attached));
            self::assertSame($before, $f->member($receipt));
            self::assertSame($events, $db->table('vehicle_damage_item_events')->get()->getResultArray());
            self::assertCount(2, $evidence->gallery(1, 10, $condition, $membership));
            self::assertNotNull($evidence->resolve(1, 10, $condition, $membership, $attached['evidence_ids'][0]));
            self::assertGreaterThan(0, $db->table('audit_logs')->where('table_name', 'vehicle_damage_item_evidence')->where('record_id', $attached['evidence_ids'][0])->countAllResults());
            $rows = $f->schema->rows();
            $late['photos'] = array_map(static fn (array $p): array => ['role' => $p['role'], 'descriptor' => $p['descriptor']], $normalized);
            self::assertTrue($f->service->attachPhotos(1, 10, $condition, $membership, $late, 7)['replayed']);
            self::assertSame($rows, $f->schema->rows());
            self::assertFalse($f->service->attachPhotos(2, 20, $condition, $membership, $late, 7)['success']);
            self::assertSame($rows, $f->schema->rows());
            // Reused checksum content survives a later failed attachment transaction.
            $writer = new class ($db) extends Evidence {
                public function attach(int $c, int $v, int $i, int $m, array $p, int $a): array
                {
                    parent::attach($c, $v, $i, $m, $p, $a);
                    throw new \RuntimeException('Synthetic attachment failure after checksum reuse.');
                }
            };
            $failed = (new VehicleWheelDamageService($db, $writer))->attachPhotos(
                1,
                10,
                $condition,
                $membership,
                ['command_uuid' => \App\Services\Fleet\VehicleDamageRepairService::commandKey(), 'photos' => [['role' => 'full_wheel', 'upload' => $f->upload()]]],
                7
            );
            self::assertFalse($failed['success']);
            self::assertSame($rows, $f->schema->rows());
            self::assertFileExists($resolved['path']);
        } elseif ($case === 'security') {
            $before = $f->schema->rows();
            foreach ([[2, 20, $condition, $membership, $photo], [1, 11, $condition, $membership, $photo], [1, 10, 999, $membership, $photo], [1, 10, $condition, 999, $photo], [1, 10, $condition, $membership, 999]] as $scope) {
                self::assertNull($evidence->resolve(...$scope));
            }
            $invalidRow = $db->table('vehicle_damage_item_evidence')->where('id', $photo)->get()->getRowArray();
            unset($invalidRow['id']);
            $invalidRow['file_id'] = 801;
            $db->table('vehicle_damage_item_evidence')->insert($invalidRow);
            $invalidId = (int) $db->insertID();
            self::assertCount(1, $evidence->gallery(1, 10, $condition, $membership));
            self::assertNull($evidence->resolve(1, 10, $condition, $membership, $invalidId));
            $db->table('vehicle_damage_item_evidence')->where('id', $invalidId)->delete();
            $fileId = (int) $db->table('vehicle_damage_item_evidence')->where('id', $photo)->get()->getRowArray()['file_id'];
            $original = $db->table('files')->where('id', $fileId)->get()->getRowArray();
            foreach ([['path' => '../public/photo.png'], ['storage_disk' => 'public'], ['deleted_at' => '2026-10-02 10:00:00'], ['checksum' => str_repeat('0', 64)], ['mime_type' => 'image/jpeg']] as $change) {
                $db->table('files')->where('id', $fileId)->update($change);
                self::assertNull($evidence->resolve(1, 10, $condition, $membership, $photo));
                $db->table('files')->where('id', $fileId)->update($original);
            }
            $bytes = file_get_contents($resolved['path']);
            unlink($resolved['path']);
            self::assertNull($evidence->resolve(1, 10, $condition, $membership, $photo));
            file_put_contents($resolved['path'], $bytes);
            self::assertSame($before, $f->schema->rows());
        } elseif ($case === 'limits') {
            $photos = [];
            for ($i = 0; $i < 5; ++$i) {
                $photos[] = ['role' => 'close_up', 'upload' => $f->upload()];
            }
            self::assertTrue($f->service->attachPhotos(1, 10, $condition, $membership, ['command_uuid' => \App\Services\Fleet\VehicleDamageRepairService::commandKey(), 'photos' => $photos], 7)['success']);
            $before = $f->schema->rows();
            self::assertFalse($f->service->attachPhotos(1, 10, $condition, $membership, ['command_uuid' => \App\Services\Fleet\VehicleDamageRepairService::commandKey(), 'photos' => [['role' => 'close_up', 'upload' => $f->upload()]]], 7)['success']);
            self::assertSame($before, $f->schema->rows());
            self::assertCount(6, $evidence->gallery(1, 10, $condition, $membership));
            $db->transBegin();
            $items = new VehicleDamageRepository($db);
            $values = $db->table('vehicle_damage_item_evidence')->where('id', $photo)->get()->getRowArray();
            unset($values['id']);
            for ($i = 0; $i < 10; ++$i) {
                $items->insertEvidence($values);
            }
            $db->transCommit();
            $page = $evidence->gallery(1, 10, $condition, $membership);
            self::assertCount(12, $page);
            self::assertCount(4, $evidence->gallery(1, 10, $condition, $membership, (int) $page[11]['id']));
        } elseif ($case === 'alias') {
            $items = new VehicleDamageRepository($db);
            $target = $db->table('vehicle_damage_items')->where('id', $condition)->get()->getRowArray();
            unset($target['id']);
            $db->table('vehicle_damage_items')->insert($target);
            $targetId = (int) $db->insertID();
            $db->table('vehicle_damage_items')->where('id', $condition)->update(['current_condition_item_id' => $targetId]);
            self::assertNotNull($evidence->resolve(1, 10, $condition, $membership, $photo));
            self::assertNull($evidence->resolve(1, 10, $targetId, $membership, $photo));
            $db->table('vehicle_damage_items')->where('id', $targetId)->update(['company_id' => 2, 'fleet_vehicle_id' => 20]);
            self::assertNull($evidence->resolve(1, 10, $condition, $membership, $photo));
            $db->table('vehicle_damage_items')->where('id', $targetId)->update(['company_id' => 1, 'fleet_vehicle_id' => 10, 'current_condition_item_id' => $condition]);
            self::assertNull($evidence->resolve(1, 10, $condition, $membership, $photo));
        }
    }
}
