<?php

namespace App\Database\Migrations;

use App\Database\VehicleDamageOccurrenceProvenanceSchema as Schema;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Migration;
use RuntimeException;

/** Forward-only additive provenance; no business DML or guessed historical links. */
class AddVehicleDamageOccurrenceProvenance extends Migration
{
    public function up(): void
    {
        if (! $this->db instanceof BaseConnection || ! Schema::matchesStage($this->db, 0)) {
            throw new RuntimeException('000036 found unexpected or partial schema. Inspect DDL and ledger; never blindly rerun.');
        }
        foreach (Schema::statements($this->db) as $index => $sql) {
            if (! Schema::matchesStage($this->db, $index) || $this->db->query($sql) === false || ! Schema::matchesStage($this->db, $index + 1)) {
                throw new RuntimeException('000036 DDL failed or schema differs. Keep provenance writes disabled; inspect DDL and ledger before recovery.');
            }
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Occurrence provenance is forward-only history. Destructive rollback is refused. Restore an approved backup through the protected recovery process.');
    }
}
