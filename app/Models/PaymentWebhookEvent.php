<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentWebhookEvent extends Model
{
    protected $fillable = [
        'provider',
        'provider_event_id',
        'provider_reference',
        'event_type',
        'payload',
        'status',
        'processing_result',
        'processing_attempts',
        'signature_verified_at',
        'processing_started_at',
        'processed_at',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'processing_attempts' => 'integer',
            'signature_verified_at' => 'datetime',
            'processing_started_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }
}
