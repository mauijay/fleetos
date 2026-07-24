<?php

namespace App\Repositories;

use App\DTOs\Turo\NormalizedTransactionData;
use App\Services\Turo\TuroTransactionEventClassMapper;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

class TuroNormalizedTransactionRepository
{
    private BaseConnection $db;
    private TuroTransactionEventClassMapper $eventClassMapper;

    public function __construct(?BaseConnection $db = null, ?TuroTransactionEventClassMapper $eventClassMapper = null)
    {
        $this->db = $db ?? Database::connect();
        $this->eventClassMapper = $eventClassMapper ?? new TuroTransactionEventClassMapper();
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
            'event_class' => $transaction->eventClass,
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

    /** @return array<string, array{count:int, amount:string}> */
    public function totalsByCategory(): array
    {
        $rows = $this->db->table('turo_transactions_normalized')
            ->select('normalized_type, COUNT(*) AS row_count, COALESCE(SUM(amount), 0) AS amount_total', false)
            ->groupBy('normalized_type')
            ->get()
            ->getResultArray();

        $totals = [];
        foreach ($rows as $row) {
            $category = (string) ($row['normalized_type'] ?? 'other');
            $totals[$category] = [
                'count' => (int) ($row['row_count'] ?? 0),
                'amount' => number_format((float) ($row['amount_total'] ?? 0), 2, '.', ''),
            ];
        }

        return $totals;
    }

    /** @return array<string, array{count:int, amount:string}> */
    public function totalsByEventClass(): array
    {
        $rows = $this->db->table('turo_transactions_normalized')
            ->select('event_class, COUNT(*) AS row_count, COALESCE(SUM(amount), 0) AS amount_total', false)
            ->groupBy('event_class')
            ->get()
            ->getResultArray();

        $totals = [];
        foreach ($rows as $row) {
            $eventClass = (string) ($row['event_class'] ?? 'other');
            $totals[$eventClass] = [
                'count' => (int) ($row['row_count'] ?? 0),
                'amount' => number_format((float) ($row['amount_total'] ?? 0), 2, '.', ''),
            ];
        }

        return $totals;
    }

    /** @return array<string, array{count:int, amount:string}> */
    public function totalsByEventClassInPeriod(string $fromDate, string $toDateExclusive): array
    {
        $rows = $this->db->table('turo_transactions_normalized')
            ->select('event_class, COUNT(*) AS row_count, COALESCE(SUM(amount), 0) AS amount_total', false)
            ->where('transaction_date >=', $fromDate)
            ->where('transaction_date <', $toDateExclusive)
            ->groupBy('event_class')
            ->get()
            ->getResultArray();

        $totals = [];
        foreach ($rows as $row) {
            $eventClass = (string) ($row['event_class'] ?? 'other');
            $totals[$eventClass] = [
                'count' => (int) ($row['row_count'] ?? 0),
                'amount' => number_format((float) ($row['amount_total'] ?? 0), 2, '.', ''),
            ];
        }

        return $totals;
    }

    public function operatingRevenueInPeriod(string $fromDate, string $toDateExclusive): float
    {
        $row = $this->db->table('turo_transactions_normalized')
            ->select('COALESCE(SUM(amount), 0) AS total', false)
            ->where('event_class', 'operating_revenue')
            ->where('transaction_date >=', $fromDate)
            ->where('transaction_date <', $toDateExclusive)
            ->get()
            ->getRowArray();

        return (float) ($row['total'] ?? 0);
    }

    /** @return array<int, array<string, mixed>> */
    public function operatingRevenueByVehicleInPeriod(string $fromDate, string $toDateExclusive): array
    {
        return $this->db->table('turo_transactions_normalized tn')
            ->select('fv.id AS fleet_vehicle_id, fv.fleet_code, fv.display_name')
            ->select('vtl.is_premium, vtl.code AS trim_code, vm.name AS vehicle_type')
            ->select('COALESCE(SUM(tn.amount), 0) AS completed_revenue', false)
            ->select('COALESCE(SUM(tn.amount), 0) AS host_payout', false)
            ->select('COUNT(*) AS transaction_count', false)
            ->join('fleet_vehicles fv', 'fv.id = tn.fleet_vehicle_id', 'left')
            ->join('vehicle_trim_levels vtl', 'vtl.id = fv.vehicle_trim_level_id', 'left')
            ->join('vehicle_specs vsp', 'vsp.id = fv.vehicle_spec_id', 'left')
            ->join('vehicle_models vm', 'vm.id = vsp.vehicle_model_id', 'left')
            ->where('tn.event_class', 'operating_revenue')
            ->where('tn.fleet_vehicle_id IS NOT NULL')
            ->where('tn.transaction_date >=', $fromDate)
            ->where('tn.transaction_date <', $toDateExclusive)
            ->groupBy('fv.id, fv.fleet_code, fv.display_name, vtl.is_premium, vtl.code, vm.name')
            ->orderBy('completed_revenue', 'DESC')
            ->get()
            ->getResultArray();
    }

    public function lifetimeOperatingRevenue(): float
    {
        $row = $this->db->table('turo_transactions_normalized')
            ->select('COALESCE(SUM(amount), 0) AS total', false)
            ->where('event_class', 'operating_revenue')
            ->get()
            ->getRowArray();

        return (float) ($row['total'] ?? 0);
    }

    /** @return array<int, array{group:string,completed_revenue:float,row_count:int}> */
    public function operatingRevenueByPremiumBaseInPeriod(string $fromDate, string $toDateExclusive): array
    {
        $rows = $this->db->table('turo_transactions_normalized tn')
            ->select("CASE WHEN COALESCE(vtl.is_premium, 0) = 1 THEN 'premium' ELSE 'base' END AS segment", false)
            ->select('COUNT(*) AS row_count', false)
            ->select('COALESCE(SUM(tn.amount), 0) AS amount_total', false)
            ->join('fleet_vehicles fv', 'fv.id = tn.fleet_vehicle_id', 'left')
            ->join('vehicle_trim_levels vtl', 'vtl.id = fv.vehicle_trim_level_id', 'left')
            ->where('tn.event_class', 'operating_revenue')
            ->where('tn.transaction_date >=', $fromDate)
            ->where('tn.transaction_date <', $toDateExclusive)
            ->groupBy('segment')
            ->get()
            ->getResultArray();

        $segments = [
            'premium' => ['group' => 'premium', 'completed_revenue' => 0.0, 'row_count' => 0],
            'base' => ['group' => 'base', 'completed_revenue' => 0.0, 'row_count' => 0],
        ];

        foreach ($rows as $row) {
            $segment = (string) ($row['segment'] ?? 'base');
            if (! isset($segments[$segment])) {
                continue;
            }

            $segments[$segment]['completed_revenue'] = round((float) ($row['amount_total'] ?? 0), 2);
            $segments[$segment]['row_count'] = (int) ($row['row_count'] ?? 0);
        }

        return array_values($segments);
    }

    /**
     * Revenue safeguard: by default, revenue totals exclude cash movement rows.
     *
     * @return array{operating_revenue:string,cash_movement:string,other:string,default_revenue_total:string,all_event_classes_total:string}
     */
    public function reportingTotals(bool $includeAccountPayments = false): array
    {
        $totals = $this->totalsByEventClass();
        $operatingRevenue = (float) ($totals['operating_revenue']['amount'] ?? 0.0);
        $cashMovement = (float) ($totals['cash_movement']['amount'] ?? 0.0);
        $other = (float) ($totals['other']['amount'] ?? 0.0);

        $defaultRevenue = $operatingRevenue + ($includeAccountPayments ? $cashMovement : 0.0);
        $allEventClasses = 0.0;
        foreach ($totals as $eventClassTotal) {
            $allEventClasses += (float) $eventClassTotal['amount'];
        }

        return [
            'operating_revenue' => number_format($operatingRevenue, 2, '.', ''),
            'cash_movement' => number_format($cashMovement, 2, '.', ''),
            'other' => number_format($other, 2, '.', ''),
            'default_revenue_total' => number_format($defaultRevenue, 2, '.', ''),
            'all_event_classes_total' => number_format($allEventClasses, 2, '.', ''),
        ];
    }

    public function backfillEventClasses(): int
    {
        $rows = $this->db->table('turo_transactions_normalized')
            ->select('id, normalized_type, event_class')
            ->get()
            ->getResultArray();

        $updated = 0;
        foreach ($rows as $row) {
            $target = $this->eventClassMapper->eventClassForType((string) ($row['normalized_type'] ?? 'other'));
            $current = (string) ($row['event_class'] ?? '');

            if ($current === $target) {
                continue;
            }

            $this->db->table('turo_transactions_normalized')
                ->where('id', (int) $row['id'])
                ->update([
                    'event_class' => $target,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);

            $updated++;
        }

        return $updated;
    }
}
