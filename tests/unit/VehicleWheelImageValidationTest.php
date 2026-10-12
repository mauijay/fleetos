<?php

namespace Tests\Unit;

use App\Services\Fleet\VehicleDamageOccurrenceEvidenceService as Evidence;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

final class VehicleWheelImageValidationTest extends CIUnitTestCase
{
    public static function formats(): iterable
    {
        foreach (['jpeg', 'png', 'webp'] as $format) {
            yield $format => [$format];
        }
    }

    #[DataProvider('formats')]
    public function testBytesDetermineImageFormat(string $format): void
    {
        $path = $this->image($format);
        $db = Database::connect((new Database())->tests, false);
        try {
            $upload = new UploadedFile($path, 'synthetic-fake.pdf', 'application/pdf', 1, UPLOAD_ERR_OK);
            $descriptor = (new Evidence($db))->descriptor($upload);
            self::assertSame('image/' . $format, $descriptor['mime_type']);
            self::assertSame(filesize($path), $descriptor['size_bytes']);
            self::assertSame(hash_file('sha256', $path), $descriptor['checksum']);
            self::assertSame(30, $descriptor['width']);
            self::assertSame(20, $descriptor['height']);
        } finally {
            unlink($path);
            $db->close();
        }
    }

    public static function invalidImages(): iterable
    {
        foreach (['heic', 'pdf', 'svg', 'text', 'empty', 'corrupt', 'bad_crc', 'polyglot', 'jpeg_append', 'webp_append', 'oversize', 'wide', 'high', 'megapixels', 'decoder', 'upload_error'] as $case) {
            yield $case => [$case];
        }
    }

    #[DataProvider('invalidImages')]
    public function testInvalidBytesAndResourceLimits(string $case): void
    {
        $path = $this->image(match ($case) {
            'jpeg_append' => 'jpeg', 'webp_append' => 'webp', default => 'png'
        });
        $bytes = file_get_contents($path);
        $bytes = match ($case) {
            'heic' => "\0\0\0\x18ftypheic" . str_repeat("\0", 30),
            'pdf' => "%PDF-1.4\nSynthetic rejected document.",
            'svg' => '<svg xmlns="http://www.w3.org/2000/svg"><rect width="1" height="1"/></svg>',
            'text' => 'Synthetic non-image with a PNG extension.',
            'empty' => '',
            'corrupt' => substr($bytes, 0, 40),
            'bad_crc' => substr_replace($bytes, 'BAD!', 29, 4),
            'polyglot' => $bytes . '<?php echo "synthetic";',
            'jpeg_append', 'webp_append' => $bytes . '<html>Appended content</html>',
            'oversize' => $bytes . str_repeat('x', Evidence::MAX_IMAGE_BYTES),
            default => $bytes,
        };
        if (in_array($case, ['wide', 'high', 'megapixels'], true)) {
            $width = $case === 'wide' ? 10001 : ($case === 'megapixels' ? 5000 : 30);
            $height = $case === 'high' ? 10001 : ($case === 'megapixels' ? 5000 : 20);
            $bytes = substr_replace($bytes, pack('NN', $width, $height), 16, 8);
            $bytes = substr_replace($bytes, hash('crc32b', substr($bytes, 12, 17), true), 29, 4);
        }
        if ($case === 'decoder') {
            // Complete, CRC-valid PNG container with no pixel data: decoder must reject.
            $bytes = substr($bytes, 0, 33) . pack('N', 0) . 'IEND' . hash('crc32b', 'IEND', true);
        }
        file_put_contents($path, $bytes);
        clearstatcache(true, $path);
        $db = Database::connect((new Database())->tests, false);
        try {
            $this->expectException(InvalidArgumentException::class);
            (new Evidence($db))->descriptor(new UploadedFile($path, 'synthetic-image.png', 'image/png', strlen($bytes), $case === 'upload_error' ? UPLOAD_ERR_PARTIAL : UPLOAD_ERR_OK));
        } finally {
            unlink($path);
            $db->close();
        }
    }

    public static function invalidCommands(): iterable
    {
        yield 'thirteen-images' => ['count'];
        yield 'aggregate-over-30-MiB' => ['aggregate'];
        yield 'raw-file-ID' => ['raw'];
        yield 'unknown-photo-role' => ['role'];
        yield 'non-list' => ['list'];
    }

    #[DataProvider('invalidCommands')]
    public function testCommandLimitsAndTrustedDescriptors(string $case): void
    {
        $descriptor = ['checksum' => str_repeat('a', 64), 'mime_type' => 'image/png', 'size_bytes' => 8388608, 'original_filename' => 'synthetic.png', 'width' => 100, 'height' => 100];
        $photo = ['role' => 'close_up', 'descriptor' => $descriptor];
        $photos = match ($case) {
            'count' => array_fill(0, 13, $photo),
            'aggregate' => array_fill(0, 4, $photo),
            'raw' => [$photo + ['file_id' => 800]],
            'role' => [array_replace($photo, ['role' => 'guess_clock_position'])],
            'list' => [3 => $photo],
            default => throw new \LogicException('Unknown test case.'),
        };
        $db = Database::connect((new Database())->tests, false);
        try {
            $this->expectException(InvalidArgumentException::class);
            (new Evidence($db))->normalize($photos);
        } finally {
            $db->close();
        }
    }

    private function image(string $format): string
    {
        $path = tempnam(sys_get_temp_dir(), 'wheel-validation-');
        $image = imagecreatetruecolor(30, 20);
        match ($format) {
            'jpeg' => imagejpeg($image, $path),
            'webp' => imagewebp($image, $path),
            default => imagepng($image, $path),
        };
        imagedestroy($image);
        return $path;
    }
}
