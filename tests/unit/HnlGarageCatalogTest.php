<?php

use App\Services\Fleet\HnlGarageCatalog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HnlGarageCatalogTest extends TestCase
{
    #[DataProvider('validParkingProvider')]
    public function testValidParking(string $garage, int $level, string $row): void
    {
        $this->assertSame(
            ['garage_code' => $garage, 'level' => $level, 'row' => $row],
            (new HnlGarageCatalog())->validate($garage, $level, $row),
        );
    }

    public static function validParkingProvider(): array
    {
        return [
            'International L7 F' => ['international', 7, 'F'],
            'Terminal 1 L8 C' => ['terminal_1', 8, 'C'],
            'Terminal 2 L6 M' => ['terminal_2', 6, 'M'],
        ];
    }

    public function testInvalidLevelAndConflictingRowAreRejected(): void
    {
        $catalog = new HnlGarageCatalog();
        try {
            $catalog->validate('terminal_2', 7, 'M');
            $this->fail('Terminal 2 level 7 must be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('level', $exception->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $catalog->validate('international', 4, 'M');
    }

    public function testRowsDeriveTheirOnlyValidGarage(): void
    {
        $catalog = new HnlGarageCatalog();

        $this->assertSame('terminal_2', $catalog->garageForRow('M'));
        $this->assertSame('terminal_1', $catalog->garageForRow('C'));
        $this->assertSame('international', $catalog->garageForRow('F'));
        $this->assertSame('terminal_2', $catalog->validate(null, 4, 'M')['garage_code']);
    }

    public function testCanonicalLocationLabelUsesLevelRowGarageOrderAndOptionalDetail(): void
    {
        $catalog = new HnlGarageCatalog();

        $this->assertSame(
            'Level 7 · Row G · International Garage · Near elevators',
            $catalog->locationLabel('international', 7, 'G', 'Near elevators'),
        );
        $this->assertSame('Row C · Terminal 1 Garage', $catalog->locationLabel(null, null, 'C'));
        $this->assertSame('Terminal 2 Garage', $catalog->locationLabel('terminal_2', null, null));
        $this->assertSame('Near elevators', $catalog->locationLabel(null, null, null, 'Near elevators'));
        $this->assertNull($catalog->locationLabel(null, null, null));
    }

    #[DataProvider('rowGarageProvider')]
    public function testCanonicalLocationLabelDerivesEveryGarageFromItsCatalogRow(string $row, string $garageName): void
    {
        $this->assertSame(
            'Level 4 · Row ' . $row . ' · ' . $garageName,
            (new HnlGarageCatalog())->locationLabel(null, 4, $row),
        );
    }

    public static function rowGarageProvider(): array
    {
        return [
            'International' => ['F', 'International Garage'],
            'Terminal 1' => ['A', 'Terminal 1 Garage'],
            'Terminal 2' => ['M', 'Terminal 2 Garage'],
        ];
    }

    public function testDefinitionOwnsColorAndTuroApproval(): void
    {
        $catalog = new HnlGarageCatalog();

        $this->assertSame('Blue', $catalog->definition('international')['color']);
        $this->assertTrue($catalog->definition('international')['approved_turo_garage']);
        $this->assertFalse($catalog->definition('terminal_1')['approved_turo_garage']);
        $this->assertFalse($catalog->definition('terminal_2')['approved_turo_garage']);
        $this->assertArrayNotHasKey('stall', $catalog->definition('international'));
    }

    public function testLegacySpaceshipDetailParsesOnlyRecognizedPattern(): void
    {
        $catalog = new HnlGarageCatalog();

        $this->assertSame(
            ['garage_code' => 'international', 'level' => 7, 'row' => 'F'],
            $catalog->parseLegacyDetail('International Garage L7 RF'),
        );
        $this->assertNull($catalog->parseLegacyDetail('somewhere around garage level seven'));
    }
}
