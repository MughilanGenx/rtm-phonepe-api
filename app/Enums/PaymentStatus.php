<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case INITIATED = 'initiated';
    case PENDING   = 'pending';
    case SUCCESS   = 'success';
    case ERROR     = 'error';
    case DECLINED  = 'declined';
    case CANCELLED = 'cancelled';
    case COMPLETED = 'completed';

    public function label(): string
    {
        return match($this) {
            self::INITIATED => 'Initiated',
            self::PENDING => 'Pending',
            self::SUCCESS => 'Success',
            self::ERROR => 'Error',
            self::DECLINED => 'Declined',
            self::CANCELLED => 'Cancelled',
            self::COMPLETED => 'Completed',
        };
    }
}
