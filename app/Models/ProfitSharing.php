<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProfitSharing extends Model
{
    use BelongsToTenant;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PAID = 'paid';

    protected $fillable = [
        'tenant_id',
        'investor_id',
        'period_month',
        'net_profit_base',
        'share_percent_snapshot',
        'payout_amount',
        'status',
        'paid_at',
        'journal_id',
    ];

    protected function casts(): array
    {
        return [
            'net_profit_base' => 'decimal:2',
            'share_percent_snapshot' => 'decimal:2',
            'payout_amount' => 'decimal:2',
            'paid_at' => 'datetime',
        ];
    }

    public function investor(): BelongsTo
    {
        return $this->belongsTo(Investor::class);
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }
}
