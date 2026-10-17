<?php

use CodeIgniter\Test\CIUnitTestCase;
use Tests\Support\GuestCommitmentProjectionFixture;
use Tests\Support\OperationalReconciliationAssertions;
use Tests\Support\OperationalReconciliationFixture;

/** @internal */
final class FleetOperationalReconciliationTest extends CIUnitTestCase
{
    use OperationalReconciliationAssertions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = GuestCommitmentProjectionFixture::sqlite();
        // sqlite() already seeded Extras; only add the reconciliation parents.
        OperationalReconciliationFixture::vehicle($this->connection, OperationalReconciliationFixture::VEHICLE);
        OperationalReconciliationFixture::trip($this->connection);
    }

    protected function tearDown(): void
    {
        $this->connection->close();
        parent::tearDown();
    }
}
