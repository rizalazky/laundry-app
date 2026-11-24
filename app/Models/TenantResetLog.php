<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantResetLog extends Model
{
    use BelongsToTenant;

    public const SCOPE_TRANSACTIONS_ONLY = 'transactions_only';

    public const SCOPE_FULL_FINANCIALS = 'full_financials';

    protected $fillable = [
        'tenant_id',
        'executed_by',
        'reset_scope',
        'records_purged_count',
        'executed_at',
    ];

    protected function casts(): array
    {
        return [
            'records_purged_count' => 'integer',
            'executed_at' => 'datetime',
        ];
    }

    public function executor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'executed_by');
    }
}
