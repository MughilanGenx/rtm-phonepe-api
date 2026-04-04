<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    /** @use HasFactory<\Database\Factories\PaymentFactory> */
    use HasFactory;

    protected $fillable = [
        'merchant_order_id',
        'customer_name',
        'customer_email',
        'customer_phone',
        'amount',
        'status',
        'transaction_id',
        'phonepe_order_id',
        'payment_response',
        'phone_link',
        'last_synced_at',
    ];

    protected $casts = [
        'status' => \App\Enums\PaymentStatus::class,
        'payment_response' => 'array',
        'last_synced_at' => 'datetime',
    ];
}
