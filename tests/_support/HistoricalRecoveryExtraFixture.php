<?php

namespace Tests\Support;

use App\Repositories\TripExtraFulfillmentRepository;
use App\Services\Fleet\TripExtraFulfillmentService;
use CodeIgniter\Database\BaseConnection;

/** Persisted synthetic purchased Extra, including unresolved historical review. */
final class HistoricalRecoveryExtraFixture
{
    /** Simulate a separately committed source/completion change, never a repair side effect. */
    public static function change(BaseConnection $db, int $selection, string $change): void
    {
        $trip = HistoricalMissingRecoveryFixture::TRIP;
        $fulfillment = $db->table('trip_extra_fulfillments')->where('turo_extra_selection_id', $selection)->get()->getRowArray();
        match ($change) {
            'actionable' => $db->table('fleet_extras')->where('company_id', HistoricalMissingRecoveryFixture::COMPANY)->update(['fulfillment_phase' => 'entire_trip']),
            'completed' => $db->table('trip_extra_fulfillments')->where('turo_extra_selection_id', $selection)->update(['state' => 'completed', 'completed_basis_hash' => $fulfillment['current_basis_hash'], 'completed_at' => '2023-06-08 09:00:00', 'completed_by_user_id' => HistoricalMissingRecoveryFixture::ACTOR]),
            'fulfillment_note' => $db->table('trip_extra_fulfillments')->where('turo_extra_selection_id', $selection)->update(['completion_note' => 'Synthetic independent edit']),
            'snapshot' => $db->table('turo_extra_reservation_snapshots')->where('turo_trip_normalized_id', $trip)->update(['source_payload_hash' => hash('sha256', 'synthetic changed source')]),
            'selection' => $db->table('turo_extra_selections')->where('id', $selection)->update(['quantity' => '3.000']),
            'lifecycle' => $db->table('turo_trips_normalized')->where('id', $trip)->update(['trip_status_lookup_value_id' => (new \App\Repositories\LookupRepository($db))->valueId('trip_status', 'in_progress')]),
            'manual' => GuestCommitmentProjectionFixture::manual($db, ['company_id' => HistoricalMissingRecoveryFixture::COMPANY, 'turo_trip_normalized_id' => $trip, 'created_by_user_id' => HistoricalMissingRecoveryFixture::ACTOR, 'updated_by_user_id' => HistoricalMissingRecoveryFixture::ACTOR]),
            default => throw new \RuntimeException('Unsupported synthetic commercial change.'),
        };
    }

    public static function seed(BaseConnection $db, int $trip = HistoricalMissingRecoveryFixture::TRIP): int
    {
        $company = HistoricalMissingRecoveryFixture::COMPANY;
        $stamp = '2023-06-06 09:00:00';
        $db->table('fleet_extras')->insert(['company_id' => $company, 'code' => 'synthetic_recovery_kit_' . $trip, 'display_name' => 'Synthetic recovery kit', 'active' => 1,
            'fulfillment_type' => 'pack', 'fulfillment_phase' => 'preparation', 'requires_operator_confirmation' => 1, 'readiness_blocking' => 1,
            'default_action_label' => 'Pack synthetic kit', 'created_at' => $stamp, 'updated_at' => $stamp]);
        $extra = (int) $db->insertID();
        $source = 'SYNTHETIC-KIT-' . $trip;
        $db->table('fleet_extra_source_mappings')->insert(['company_id' => $company, 'source_system' => 'turo', 'source_extra_id' => $source, 'fleet_extra_id' => $extra,
            'first_seen_at' => $stamp, 'last_seen_at' => $stamp, 'created_at' => $stamp, 'updated_at' => $stamp]);
        $item = GuestCommitmentProjectionFixture::item(['extra_id' => $source, 'reservation_state_extra_id' => 'SYNTHETIC-LINE-' . $trip, 'label' => 'Synthetic recovery kit']);
        $payload = json_encode(['extras' => [$item]], JSON_THROW_ON_ERROR);
        $snapshot = $db->table('turo_extra_reservation_snapshots')->where('turo_trip_normalized_id', $trip)->get()->getRowArray();
        $db->table('turo_extra_reservation_snapshots')->where('id', $snapshot['id'])->update(['observed_at' => $stamp, 'source_payload_hash' => hash('sha256', $payload), 'source_payload' => $payload]);
        $db->table('turo_extra_selections')->insert(['company_id' => $company, 'turo_trip_normalized_id' => $trip, 'turo_reservation_id' => $snapshot['turo_reservation_id'],
            'first_snapshot_id' => $snapshot['id'], 'last_snapshot_id' => $snapshot['id'], 'source_extra_id' => $source, 'reservation_state_extra_id' => $item['reservation_state_extra_id'],
            'source_label' => $item['label'], 'quantity' => $item['quantity'], 'unit_price' => $item['price'], 'currency_code' => $item['currency'], 'source_payload_hash' => hash('sha256', json_encode($item, JSON_THROW_ON_ERROR)),
            'source_payload' => json_encode($item, JSON_THROW_ON_ERROR), 'first_observed_at' => $stamp, 'last_observed_at' => $stamp, 'created_at' => $stamp, 'updated_at' => $stamp]);
        $selection = (int) $db->insertID();
        $repository = new TripExtraFulfillmentRepository($db);
        $raw = $repository->operationalRows($company, [$selection])[0];
        $basis = (new TripExtraFulfillmentService())->present($raw, [], false)['operational_basis_hash'];
        $db->table('trip_extra_fulfillments')->insert(['company_id' => $company, 'turo_extra_selection_id' => $selection, 'state' => 'pending', 'current_basis_hash' => $basis, 'created_at' => $stamp, 'updated_at' => $stamp]);
        return $selection;
    }
}
