<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case INITIATED = 'initiated';
    case PENDING   = 'pending';
    case COMPLETED = 'completed';
    case DECLINED  = 'declined';
    case CANCELLED = 'cancelled';
    case ERROR     = 'error';

    public function label(): string
    {
        return match($this) {
            self::INITIATED => 'Initiated',
            self::PENDING   => 'Pending',
            self::COMPLETED => 'Completed',
            self::DECLINED  => 'Declined',
            self::CANCELLED => 'Cancelled',
            self::ERROR     => 'Error',
        };
    }

    /**
     * Returns true if this is a terminal (non-retryable) status.
     */
    public function isTerminal(): bool
    {
        return in_array($this, [
            self::COMPLETED,
            self::DECLINED,
            self::CANCELLED,
            self::ERROR,
        ]);
    }
}
