<?php

namespace App\Services\Payments;

use App\Exceptions\ApiException;

/**
 * Notification Velto, lue et traduite.
 */
final readonly class VeltoEvent
{
    private function __construct(
        public string $event,
        public string $status,
        public string $transactionId,
        public ?string $paymentReference,
        public ?int $amount,
    ) {}

    public static function fromPayload(string $payload): self
    {
        $decoded = json_decode($payload, true);

        if (! is_array($decoded)) {
            throw new ApiException(
                'Notification Velto illisible.',
                422,
                'VELTO_MALFORMED_EVENT',
            );
        }

        $event = self::stringOrNull($decoded['event'] ?? null) ?? '';
        $status = self::stringOrNull($decoded['status'] ?? null);
        $transactionId = self::stringOrNull($decoded['transaction_id'] ?? null);

        if ($status === null || $transactionId === null || $transactionId === '') {
            throw new ApiException(
                'Notification Velto incomplete.',
                422,
                'VELTO_MALFORMED_EVENT',
                ['event' => $event],
            );
        }

        $reference = self::stringOrNull($decoded['external_reference'] ?? null);

        return new self(
            event: $event,
            status: strtoupper($status),
            transactionId: $transactionId,
            paymentReference: $reference,
            amount: self::amount($decoded['amount'] ?? null),
        );
    }

    public function concernsPayment(): bool
    {
        return str_starts_with($this->event, 'payment.');
    }

    private static function amount(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^\d+$/', trim($value)) === 1) {
            return (int) trim($value);
        }

        return null;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
