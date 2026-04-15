<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'merchant_order_id',
        'name',
        'email',
        'phone',
        'amount',
        'description',
        'status',
        'phonepe_link',       // Fixed: was 'phone_link' (typo)
        'transaction_id',
        'phonepe_order_id',
        'payment_response',
        'last_synced_at',
        'paid_at',
    ];

    protected $casts = [
        'status'           => PaymentStatus::class,
        'payment_response' => 'array',
        'last_synced_at'   => 'datetime',
        'paid_at'          => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
