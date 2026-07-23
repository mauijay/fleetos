<?php

namespace App\Repositories;

use App\DTOs\Turo\NormalizedTransactionData;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

class TuroNormalizedTransactionRepository
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /** @return array<string, mixed> */
    public function upsert(NormalizedTransactionData $transaction): array
    {
        $now = date('Y-m-d H:i:s');
        $data = [
            'turo_transaction_raw_id' => $transaction->turoTransactionRawId,
            'turo_trip_normalized_id' => $transaction->turoTripNormalizedId,
            'fleet_vehicle_id' => $transaction->fleetVehicleId,
            'external_transaction_id' => $transaction->externalTransactionId,
            'external_trip_id' => $transaction->externalTripId,
            'transaction_type' => $transaction->transactionType,
            'normalized_type' => $transaction->normalizedType,
            'description' => $transaction->description,
            'amount' => $transaction->amount,
            'currency_code' => $transaction->currencyCode,
            'transaction_date' => $transaction->transactionDate,
            'row_fingerprint' => $transaction->rowFingerprint,
            'updated_at' => $now,
        ];

        $existing = $transaction->externalTransactionId === null
            ? null
            : $this->db->table('turo_transactions_normalized')->where('external_transaction_id', $transaction->externalTransactionId)->get()->getRowArray();

        if ($existing === null) {
            $existing = $this->db->table('turo_transactions_normalized')->where('row_fingerprint', $transaction->rowFingerprint)->get()->getRowArray();
        }

        if ($existing === null) {
            $this->db->table('turo_transactions_normalized')->insert(array_merge($data, ['created_at' => $now]));

            return ['id' => (int) $this->db->insertID(), 'created' => true, 'old' => null, 'new' => $data];
        }

        $this->db->table('turo_transactions_normalized')->where('id', $existing['id'])->update($data);

        return ['id' => (int) $existing['id'], 'created' => false, 'old' => $existing, 'new' => $data];
    }
}
