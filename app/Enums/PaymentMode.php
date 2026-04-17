<?php

namespace App\Enums;

enum PaymentMode: string
{
    case UPI         = 'UPI';
    case CARD        = 'CARD';
    case NET_BANKING = 'NET_BANKING';
    // case WALLET      = 'WALLET';
    // case EMI         = 'EMI';
    // case CASH        = 'CASH';
    // case OTHER       = 'OTHER';

    /**
     * Human-readable label shown in the UI.
     */
    public function label(): string
    {
        return match ($this) {
            self::UPI         => 'UPI',
            self::CARD        => 'Card',
            self::NET_BANKING => 'Net Banking',
            // self::WALLET      => 'Wallet',
            // self::EMI         => 'EMI',
            // self::CASH        => 'Cash',
            // self::OTHER       => 'Other',
        };
    }

    /**
     * Return all cases as an array suitable for API responses.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $mode) => [
                'value' => $mode->value,
                'label' => $mode->label(),
            ],
            self::cases()
        );
    }
}
