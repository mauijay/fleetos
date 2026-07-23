<?php

namespace App\Database\Migrations;

use App\Repositories\TuroNormalizedTransactionRepository;
use CodeIgniter\Database\Migration;

class AddEventClassToTuroTransactionsNormalizedTable extends Migration
{
    public function up(): void
    {
        try {
            $this->forge->addColumn('turo_transactions_normalized', [
                'event_class' => [
                    'type' => 'VARCHAR',
                    'constraint' => 40,
                    'null' => false,
                    'default' => 'other',
                    'after' => 'normalized_type',
                ],
            ]);
        } catch (\Throwable) {
            // Ignore if the column already exists.
        }

        try {
            $this->db->query('CREATE INDEX turo_transactions_normalized_event_class_date_idx ON turo_transactions_normalized (event_class, transaction_date)');
        } catch (\Throwable) {
            // Ignore duplicate-index creation errors during reruns.
        }

        (new TuroNormalizedTransactionRepository($this->db))->backfillEventClasses();
    }

    public function down(): void
    {
        try {
            $this->db->query('DROP INDEX turo_transactions_normalized_event_class_date_idx ON turo_transactions_normalized');
        } catch (\Throwable) {
            // Ignore if index does not exist.
        }

        try {
            $this->forge->dropColumn('turo_transactions_normalized', 'event_class');
        } catch (\Throwable) {
            // Ignore if the column does not exist.
        }
    }
}
