<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RaffleTicket extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'raffle_id', 'customer_id', 'ticket_number', 'issued_at', 'is_winner',
    ];

    protected function casts(): array
    {
        return ['issued_at' => 'datetime', 'is_winner' => 'boolean'];
    }

    public function raffle(): BelongsTo
    {
        return $this->belongsTo(Raffle::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
