<?php

use App\Validation\Turo\TuroExtrasPayloadValidator;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/** @internal */
final class TuroExtrasPayloadValidatorTest extends CIUnitTestCase
{
    private TuroExtrasPayloadValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new TuroExtrasPayloadValidator();
    }

    public function testSanitizedFixturePreservesIdentityPriceAndMissingQuantity(): void
    {
        $result = $this->validator->validate($this->fixture('turo_extras_export_v1.json'));

        $this->assertSame('2026-09-13 18:00:00', $result['exported_at']);
        $this->assertCount(3, $result['reservations']);
        $this->assertSame('3199029', $result['reservations'][1]['extras'][0]['extra_id']);
        $this->assertSame('8020577', $result['reservations'][1]['extras'][0]['reservation_state_extra_id']);
        $this->assertSame('30.00', $result['reservations'][1]['extras'][0]['price']);
        $this->assertNull($result['reservations'][1]['extras'][0]['quantity']);
        $this->assertSame([], $result['invalid']);
    }

    public function testSecondPremiumFixtureProvidesOneSanitizedAlternateSourceIdentity(): void
    {
        $result = $this->validator->validate($this->fixture('turo_extras_export_v1_second_premium.json'));

        $this->assertSame('2026-09-13 21:00:00', $result['exported_at']);
        $this->assertCount(1, $result['reservations']);
        $this->assertSame('79990001', $result['reservations'][0]['reservation_id']);
        $this->assertTrue($result['reservations'][0]['snapshot_complete']);
        $this->assertCount(1, $result['reservations'][0]['extras']);

        $extra = $result['reservations'][0]['extras'][0];
        $this->assertSame('4100001', $extra['extra_id']);
        $this->assertSame('8020580', $extra['reservation_state_extra_id']);
        $this->assertSame('BEACH_GEAR', $extra['type']);
        $this->assertSame('Beach gear', $extra['label']);
        $this->assertSame('Sanitized premium beach equipment fixture using an alternate Turo source identity.', $extra['description']);
        $this->assertSame('47.00', $extra['price']);
        $this->assertSame('1.000', $extra['quantity']);
        $this->assertSame('USD', $extra['currency']);
        $this->assertSame('PER_TRIP', $extra['pricing_type']);
        $this->assertSame([], $result['invalid']);
    }

    public function testMalformedReservationIsIsolatedWhileValidReservationSurvives(): void
    {
        $payload = json_decode($this->fixture('turo_extras_export_v1.json'), true, 64, JSON_THROW_ON_ERROR);
        $payload['reservations'][1]['extras'][0]['guest_email'] = 'must-not-pass@example.test';

        $result = $this->validator->validate(json_encode($payload, JSON_THROW_ON_ERROR));

        $this->assertCount(2, $result['reservations']);
        $this->assertCount(1, $result['invalid']);
        $this->assertStringContainsString('Unexpected extra field', $result['invalid'][0]['message']);
    }

    #[DataProvider('invalidRootPayloads')]
    public function testInvalidSchemaAndPrivateOrDuplicateSourceDataAreRejected(array $payload, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        $this->validator->validate(json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public static function invalidRootPayloads(): iterable
    {
        $base = [
            'schema' => TuroExtrasPayloadValidator::SCHEMA,
            'exported_at' => '2026-09-13T10:00:00-10:00',
            'reservations' => [],
            'failures' => [],
        ];
        yield 'wrong schema' => [array_merge($base, ['schema' => 'unknown']), 'Unsupported Extras schema'];
        yield 'private root data' => [array_merge($base, ['cookies' => 'secret']), 'Unexpected root field'];
        yield 'private failure data' => [array_merge($base, ['failures' => [['reservation_id' => '7', 'error' => 'HTTP 404', 'token' => 'secret']]]), 'Unexpected failure field'];
    }

    public function testDuplicateReservationAndSelectionIdentitiesDoNotReachPersistence(): void
    {
        $payload = json_decode($this->fixture('turo_extras_export_v1.json'), true, 64, JSON_THROW_ON_ERROR);
        $payload['reservations'][] = $payload['reservations'][0];
        $result = $this->validator->validate(json_encode($payload, JSON_THROW_ON_ERROR));
        $this->assertCount(3, $result['reservations']);
        $this->assertStringContainsString('appears more than once', $result['invalid'][0]['message']);

        $payload = json_decode($this->fixture('turo_extras_export_v1.json'), true, 64, JSON_THROW_ON_ERROR);
        $payload['reservations'][0]['extras'][] = $payload['reservations'][0]['extras'][0];
        $result = $this->validator->validate(json_encode($payload, JSON_THROW_ON_ERROR));
        $this->assertCount(2, $result['reservations']);
        $this->assertStringContainsString('repeats reservation_state_extra_id', $result['invalid'][0]['message']);
    }

    private function fixture(string $name): string
    {
        $contents = file_get_contents(dirname(__DIR__) . '/_support/fixtures/' . $name);
        $this->assertIsString($contents);

        return $contents;
    }
}
