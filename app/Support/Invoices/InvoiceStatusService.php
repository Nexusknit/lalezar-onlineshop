<?php

namespace App\Support\Invoices;

class InvoiceStatusService
{
    public const DRAFT = 'draft';
    public const PENDING = 'pending';
    public const PAYMENT_PENDING = 'payment_pending';
    public const PAYMENT_FAILED = 'payment_failed';
    public const PAID = 'paid';
    public const PROCESSING = 'processing';
    public const SHIPPED = 'shipped';
    public const DELIVERED = 'delivered';
    public const CANCELLED = 'cancelled';
    public const REFUNDED = 'refunded';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return [
            self::DRAFT,
            self::PENDING,
            self::PAYMENT_PENDING,
            self::PAYMENT_FAILED,
            self::PAID,
            self::PROCESSING,
            self::SHIPPED,
            self::DELIVERED,
            self::CANCELLED,
            self::REFUNDED,
        ];
    }

    public static function canTransition(string $from, string $to): bool
    {
        if ($from === $to) {
            return true;
        }

        $transitions = [
            self::DRAFT => [self::PENDING, self::CANCELLED],
            self::PENDING => [self::PAYMENT_PENDING, self::CANCELLED],
            self::PAYMENT_PENDING => [self::PAID, self::PAYMENT_FAILED, self::CANCELLED],
            self::PAYMENT_FAILED => [self::PAYMENT_PENDING, self::CANCELLED],
            self::PAID => [self::PROCESSING, self::REFUNDED],
            self::PROCESSING => [self::SHIPPED, self::CANCELLED, self::REFUNDED],
            self::SHIPPED => [self::DELIVERED, self::REFUNDED],
            self::DELIVERED => [self::REFUNDED],
            self::CANCELLED => [],
            self::REFUNDED => [],
        ];

        return in_array($to, $transitions[$from] ?? [], true);
    }
}
