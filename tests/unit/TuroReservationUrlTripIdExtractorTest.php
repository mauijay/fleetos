<?php

use App\Services\Turo\TuroReservationUrlTripIdExtractor;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class TuroReservationUrlTripIdExtractorTest extends CIUnitTestCase
{
    public function testExtractsTripIdFromValidReservationUrl(): void
    {
        $id = (new TuroReservationUrlTripIdExtractor())->extract('https://turo.com//reservation/59419470');

        $this->assertSame('59419470', $id);
    }

    public function testMalformedReservationUrlReturnsNull(): void
    {
        $extractor = new TuroReservationUrlTripIdExtractor();

        $this->assertNull($extractor->extract('https://turo.com/reservation/not-a-number'));
        $this->assertNull($extractor->extract('https://turo.com/reservation/'));
        $this->assertNull($extractor->extract('not a url'));
    }

    public function testMissingReservationUrlReturnsNull(): void
    {
        $extractor = new TuroReservationUrlTripIdExtractor();

        $this->assertNull($extractor->extract(null));
        $this->assertNull($extractor->extract(''));
        $this->assertNull($extractor->extract('   '));
    }

    public function testNonTripUrlsReturnNull(): void
    {
        $extractor = new TuroReservationUrlTripIdExtractor();

        $this->assertNull($extractor->extract('https://turo.com/login'));
        $this->assertNull($extractor->extract('https://example.com/reservation/59419470'));
    }
}
