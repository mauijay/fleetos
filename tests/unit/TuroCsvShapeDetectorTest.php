<?php

use App\Services\Turo\TuroCsvShapeDetector;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class TuroCsvShapeDetectorTest extends CIUnitTestCase
{
    public function testDetectsTripExportShape(): void
    {
        $detector = new TuroCsvShapeDetector();

        $this->assertTrue($detector->isTripExport(['trip_id', 'starts_at', 'ends_at', 'host_payout']));
        $this->assertFalse($detector->isEarningsExport(['trip_id', 'starts_at', 'ends_at', 'host_payout']));
    }

    public function testDetectsEarningsExportShape(): void
    {
        $detector = new TuroCsvShapeDetector();

        $this->assertTrue($detector->isEarningsExport(['transaction_id', 'transaction_type', 'amount', 'transaction_date']));
        $this->assertFalse($detector->isTripExport(['transaction_id', 'transaction_type', 'amount', 'transaction_date']));
    }
}
