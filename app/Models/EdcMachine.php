<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EdcMachine extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id', 'branch_id', 'bank_account_id', 'terminal_name', 'mdr_percent', 'is_active',
    ];

    protected function casts(): array
    {
        return ['mdr_percent' => 'decimal:2', 'is_active' => 'boolean'];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(CashSettlement::class);
    }
}
