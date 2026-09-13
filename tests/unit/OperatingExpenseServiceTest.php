<?php

use App\Repositories\AuditLogRepository;
use App\Repositories\LookupRepository;
use App\Repositories\OperatingExpenseRepository;
use App\Services\Fleet\OperatingExpenseService;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;

/** @internal */
#[AllowMockObjectsWithoutExpectations]
final class OperatingExpenseServiceTest extends CIUnitTestCase
{
    public function testRequiredPositiveDecimalAndOtherPurposeRules(): void
    {
        [$service, $repo] = $this->service();
        $repo->method('category')->willReturnCallback(static fn (string $code): ?array => in_array($code, ['fuel', 'other'], true) ? ['id' => 8, 'code' => $code] : null);

        foreach (['', '0', '-1', '1.234'] as $amount) {
            $result = $service->createManual(1, ['expense_date' => '2026-09-12', 'amount' => $amount, 'category_code' => 'fuel'], 4);
            $this->assertArrayHasKey('amount', $result['errors']);
        }
        $result = $service->createManual(1, ['expense_date' => '2026-09-12', 'amount' => '12.50', 'category_code' => 'other'], 4);
        $this->assertArrayHasKey('business_purpose', $result['errors']);
    }

    public function testFleetWideManualExpenseIgnoresClientCompanyAndUsesExactDecimalString(): void
    {
        [$service, $repo, $audit, $lookup] = $this->service();
        $repo->method('category')->willReturn(['id' => 12, 'code' => 'fuel']);
        $repo->method('possibleExpenseDuplicates')->willReturn([]);
        $repo->method('transaction')->willReturnCallback(static fn (callable $callback): mixed => $callback());
        $captured = [];
        $repo->method('createExpense')->willReturnCallback(static function (array $data) use (&$captured): int {
            $captured = $data;
            return 77;
        });
        $lookup->method('valueId')->willReturn(1);
        $audit->expects($this->once())->method('record')->with(4, 1, 'operating_expenses', 77);

        $result = $service->createManual(9, ['company_id' => 999, 'expense_date' => '2026-09-12', 'amount' => '0012.5', 'category_code' => 'fuel'], 4);

        $this->assertTrue($result['success']);
        $this->assertSame(9, $captured['company_id']);
        $this->assertSame('12.50', $captured['amount']);
        $this->assertNull($captured['fleet_vehicle_id']);
        $this->assertNull($captured['turo_trip_normalized_id']);
        $this->assertSame('manual', $captured['source_code']);
    }

    public function testTripDerivesVehicleAndMismatchFailsClosed(): void
    {
        [$service, $repo] = $this->service();
        $repo->method('category')->willReturn(['id' => 2, 'code' => 'fuel']);
        $repo->method('trip')->willReturn(['id' => 44, 'fleet_vehicle_id' => 7]);

        $this->expectException(PageNotFoundException::class);
        $service->createManual(1, ['expense_date' => '2026-09-12', 'amount' => '10', 'category_code' => 'fuel', 'turo_trip_normalized_id' => 44, 'fleet_vehicle_id' => 8], 5);
    }

    public function testPossibleDuplicateWarnsAndNeverAutoMerges(): void
    {
        [$service, $repo] = $this->service();
        $repo->method('category')->willReturn(['id' => 2, 'code' => 'fuel']);
        $repo->method('possibleExpenseDuplicates')->willReturn([['id' => 31]]);
        $repo->expects($this->never())->method('createExpense');

        $result = $service->createManual(1, ['expense_date' => '2026-09-12', 'amount' => '10', 'category_code' => 'fuel'], 5);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('similar recorded expense', $result['warning']);
    }

    public function testReceiptFirstClassificationCreatesAndLinksExpenseTransactionally(): void
    {
        [$service, $repo, $audit, $lookup] = $this->service();
        $repo->method('receipt')->willReturn([
            'id' => 5, 'company_id' => 1, 'classification_code' => 'needs_classification', 'archived_at' => null,
            'document_date' => '2026-09-12', 'observed_amount' => '18.75', 'vendor' => 'Detail Supply', 'note' => null,
        ]);
        $repo->method('category')->willReturn(['id' => 3, 'code' => 'supplies_consumables']);
        $repo->method('possibleExpenseDuplicates')->willReturn([]);
        $repo->method('transaction')->willReturnCallback(static fn (callable $callback): mixed => $callback());
        $repo->method('createExpense')->willReturn(88);
        $repo->expects($this->once())->method('updateReceipt')->with(1, 5, $this->callback(static fn (array $data): bool => $data['operating_expense_id'] === 88 && $data['classification_code'] === 'operating_expense'));
        $lookup->method('valueId')->willReturn(1);
        $audit->expects($this->exactly(3))->method('record');

        $result = $service->classifyReceipt(1, 5, ['category_code' => 'supplies_consumables'], 4);
        $this->assertTrue($result['success']);
        $this->assertSame(88, $result['id']);
    }

    public function testAuthenticatedActorIsRequired(): void
    {
        [$service] = $this->service();
        $this->expectException(InvalidArgumentException::class);
        $service->createManual(1, [], 0);
    }

    public function testExpenseDetailIncludesAllCompanyScopedReceiptEvidence(): void
    {
        [$service, $repo] = $this->service();
        $repo->expects($this->once())->method('expense')->with(7, 42)->willReturn(['id' => 42, 'company_id' => 7]);
        $repo->expects($this->once())->method('receiptsForExpense')->with(7, 42)->willReturn([
            ['id' => 11, 'company_id' => 7, 'operating_expense_id' => 42],
            ['id' => 12, 'company_id' => 7, 'operating_expense_id' => 42],
        ]);

        $detail = $service->expenseDetail(7, 42);

        $this->assertSame([11, 12], array_column($detail['receipts'], 'id'));
    }

    /** @return array{OperatingExpenseService,OperatingExpenseRepository&MockObject,AuditLogRepository&MockObject,LookupRepository&MockObject} */
    private function service(): array
    {
        $repo = $this->getMockBuilder(OperatingExpenseRepository::class)->disableOriginalConstructor()->onlyMethods(['category', 'trip', 'vehicle', 'expense', 'receipt', 'receiptsForExpense', 'possibleExpenseDuplicates', 'transaction', 'createExpense', 'updateReceipt'])->getMock();
        $audit = $this->getMockBuilder(AuditLogRepository::class)->disableOriginalConstructor()->getMock();
        $lookup = $this->getMockBuilder(LookupRepository::class)->disableOriginalConstructor()->getMock();

        return [new OperatingExpenseService($repo, $audit, $lookup), $repo, $audit, $lookup];
    }
}
