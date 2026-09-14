<?php

namespace App\Validation\Turo;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

class TuroExtrasPayloadValidator
{
    public const SCHEMA = 'fleetos-turo-extras-v1';

    private const ROOT_KEYS = ['schema', 'exported_at', 'reservations', 'failures'];
    private const RESERVATION_KEYS = ['reservation_id', 'trip_start', 'trip_end', 'status', 'snapshot_complete', 'extras'];
    private const EXTRA_KEYS = ['extra_id', 'reservation_state_extra_id', 'reservation_state_id', 'type', 'label', 'description', 'price', 'quantity', 'currency', 'pricing_type'];
    private const FAILURE_KEYS = ['reservation_id', 'error'];

    /** @return array{exported_at:string,reservations:list<array<string,mixed>>,invalid:list<array{row:int,reservation_id:?string,message:string}>,failures:list<array{reservation_id:string,error:string}>} */
    public function validate(string $json): array
    {
        if (strlen($json) > 2_097_152) {
            throw new InvalidArgumentException('The sanitized Extras JSON exceeds the 2 MB import limit.');
        }
        try {
            $payload = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidArgumentException('The Extras import is not valid JSON.');
        }
        if (! is_array($payload) || array_is_list($payload)) {
            throw new InvalidArgumentException('The Extras import root must be a JSON object.');
        }
        $this->rejectUnknownKeys($payload, self::ROOT_KEYS, 'root');
        if (($payload['schema'] ?? null) !== self::SCHEMA) {
            throw new InvalidArgumentException('Unsupported Extras schema. Expected fleetos-turo-extras-v1.');
        }
        $exportedAt = $this->dateTime($payload['exported_at'] ?? null, 'exported_at');
        if (! isset($payload['reservations']) || ! is_array($payload['reservations']) || ! array_is_list($payload['reservations'])) {
            throw new InvalidArgumentException('reservations must be a JSON array.');
        }
        if (count($payload['reservations']) > 500) {
            throw new InvalidArgumentException('An Extras import may contain at most 500 reservations.');
        }

        $valid = [];
        $invalid = [];
        $seenReservationIds = [];
        foreach ($payload['reservations'] as $index => $reservation) {
            try {
                $validatedReservation = $this->reservation($reservation);
                $reservationId = $validatedReservation['reservation_id'];
                if (isset($seenReservationIds[$reservationId])) {
                    throw new InvalidArgumentException("Reservation {$reservationId} appears more than once in the same export.");
                }
                $seenReservationIds[$reservationId] = true;
                $valid[] = $validatedReservation;
            } catch (InvalidArgumentException $exception) {
                $invalid[] = [
                    'row' => $index + 1,
                    'reservation_id' => is_array($reservation) && is_scalar($reservation['reservation_id'] ?? null) ? trim((string) $reservation['reservation_id']) : null,
                    'message' => $exception->getMessage(),
                ];
            }
        }

        $failures = $payload['failures'] ?? [];
        if (! is_array($failures) || ! array_is_list($failures)) {
            throw new InvalidArgumentException('failures must be a JSON array when supplied.');
        }
        $validatedFailures = [];
        foreach ($failures as $failure) {
            if (! is_array($failure) || array_is_list($failure)) {
                throw new InvalidArgumentException('Each exporter failure must be an object.');
            }
            $this->rejectUnknownKeys($failure, self::FAILURE_KEYS, 'failure');
            $validatedFailures[] = [
                'reservation_id' => $this->requiredString($failure['reservation_id'] ?? null, 'failure reservation_id', 80),
                'error' => $this->requiredString($failure['error'] ?? null, 'failure error', 190),
            ];
        }

        return ['exported_at' => $exportedAt, 'reservations' => $valid, 'invalid' => $invalid, 'failures' => $validatedFailures];
    }

    /** @return array<string, mixed> */
    private function reservation(mixed $reservation): array
    {
        if (! is_array($reservation) || array_is_list($reservation)) {
            throw new InvalidArgumentException('Reservation entry must be an object.');
        }
        $this->rejectUnknownKeys($reservation, self::RESERVATION_KEYS, 'reservation');
        $reservationId = $this->requiredString($reservation['reservation_id'] ?? null, 'reservation_id', 80);
        if (! array_key_exists('snapshot_complete', $reservation) || ! is_bool($reservation['snapshot_complete'])) {
            throw new InvalidArgumentException("Reservation {$reservationId} must declare boolean snapshot_complete.");
        }
        if (! isset($reservation['extras']) || ! is_array($reservation['extras']) || ! array_is_list($reservation['extras'])) {
            throw new InvalidArgumentException("Reservation {$reservationId} extras must be an array.");
        }

        $extras = [];
        $seenSelectionIds = [];
        foreach ($reservation['extras'] as $extra) {
            $validatedExtra = $this->extra($extra, $reservationId);
            $selectionId = $validatedExtra['reservation_state_extra_id'];
            if (isset($seenSelectionIds[$selectionId])) {
                throw new InvalidArgumentException("Reservation {$reservationId} repeats reservation_state_extra_id {$selectionId}.");
            }
            $seenSelectionIds[$selectionId] = true;
            $extras[] = $validatedExtra;
        }

        return [
            'reservation_id' => $reservationId,
            'trip_start' => $this->optionalDateTime($reservation['trip_start'] ?? null, 'trip_start'),
            'trip_end' => $this->optionalDateTime($reservation['trip_end'] ?? null, 'trip_end'),
            'status' => $this->optionalString($reservation['status'] ?? null, 120),
            'snapshot_complete' => $reservation['snapshot_complete'],
            'extras' => $extras,
        ];
    }

    /** @return array<string, mixed> */
    private function extra(mixed $extra, string $reservationId): array
    {
        if (! is_array($extra) || array_is_list($extra)) {
            throw new InvalidArgumentException("Reservation {$reservationId} contains an Extra that is not an object.");
        }
        $this->rejectUnknownKeys($extra, self::EXTRA_KEYS, 'extra');
        $price = $this->decimal($extra['price'] ?? null, 'price', 2, false);
        $quantity = array_key_exists('quantity', $extra) && $extra['quantity'] !== null
            ? $this->decimal($extra['quantity'], 'quantity', 3, true)
            : null;
        $currency = strtoupper($this->requiredString($extra['currency'] ?? null, 'currency', 3));
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new InvalidArgumentException("Reservation {$reservationId} contains an invalid currency code.");
        }

        return [
            'extra_id' => $this->requiredString($extra['extra_id'] ?? null, 'extra_id', 120),
            'reservation_state_extra_id' => $this->requiredString($extra['reservation_state_extra_id'] ?? null, 'reservation_state_extra_id', 120),
            'reservation_state_id' => $this->optionalString($extra['reservation_state_id'] ?? null, 120),
            'type' => $this->optionalString($extra['type'] ?? null, 120),
            'label' => $this->requiredString($extra['label'] ?? null, 'label', 190),
            'description' => $this->optionalString($extra['description'] ?? null, 4000),
            'price' => $price,
            'quantity' => $quantity,
            'currency' => $currency,
            'pricing_type' => $this->optionalString($extra['pricing_type'] ?? null, 80),
        ];
    }

    /** @param array<string,mixed> $payload @param list<string> $allowed */
    private function rejectUnknownKeys(array $payload, array $allowed, string $context): void
    {
        $unknown = array_values(array_diff(array_keys($payload), $allowed));
        if ($unknown !== []) {
            throw new InvalidArgumentException("Unexpected {$context} field: {$unknown[0]}. Export sanitized Extras fields only.");
        }
    }

    private function requiredString(mixed $value, string $field, int $max): string
    {
        if (! is_scalar($value) || trim((string) $value) === '') {
            throw new InvalidArgumentException("{$field} is required.");
        }
        $value = trim((string) $value);
        if (mb_strlen($value) > $max) {
            throw new InvalidArgumentException("{$field} is too long.");
        }

        return $value;
    }

    private function optionalString(mixed $value, int $max): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_scalar($value)) {
            throw new InvalidArgumentException('Optional text values must be strings.');
        }
        $value = trim((string) $value);
        if (mb_strlen($value) > $max) {
            throw new InvalidArgumentException('An optional text value is too long.');
        }

        return $value === '' ? null : $value;
    }

    private function dateTime(mixed $value, string $field): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException("{$field} is required.");
        }
        try {
            return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            throw new InvalidArgumentException("{$field} must be a valid date/time.");
        }
    }

    private function optionalDateTime(mixed $value, string $field): ?string
    {
        return $value === null || $value === '' ? null : $this->dateTime($value, $field);
    }

    private function decimal(mixed $value, string $field, int $precision, bool $positive): string
    {
        if (! is_scalar($value)) {
            throw new InvalidArgumentException("{$field} must be numeric.");
        }
        $value = trim((string) $value);
        if (preg_match('/^\d+(?:\.\d{1,' . $precision . '})?$/', $value) !== 1) {
            throw new InvalidArgumentException("{$field} must be a non-negative decimal with at most {$precision} decimal places.");
        }
        if ($positive && (float) $value <= 0) {
            throw new InvalidArgumentException("{$field} must be greater than zero when supplied.");
        }

        return number_format((float) $value, $precision, '.', '');
    }
}
