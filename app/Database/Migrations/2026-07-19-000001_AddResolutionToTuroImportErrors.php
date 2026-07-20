<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddResolutionToTuroImportErrors extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('turo_import_errors', [
            'resolved_at' => ['type' => 'DATETIME', 'null' => true, 'after' => 'created_at'],
            'resolution_note' => ['type' => 'TEXT', 'null' => true, 'after' => 'resolved_at'],
            'updated_at' => ['type' => 'DATETIME', 'null' => true, 'after' => 'resolution_note'],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('turo_import_errors', ['resolved_at', 'resolution_note', 'updated_at']);
    }
}
