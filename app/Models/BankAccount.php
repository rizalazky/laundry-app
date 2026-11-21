<?php

namespace App\\Models;

use App\\Models\\Concerns\\BelongsToTenant;
use Illuminate\\Database\\Eloquent\\Factories\\HasFactory;
use Illuminate\\Database\\Eloquent\\Model;
use Illuminate\\Database\\Eloquent\\Relations\\BelongsTo;
use Illuminate\\Database\\Eloquent\\Relations\\HasMany;

class BankAccount extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id',
        'coa_id',
        'bank_name',
        'account_number',
        'holder_name',
        'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'coa_id');
    }

    public function edcMachines(): HasMany
    {
        return $this->hasMany(EdcMachine::class);
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(CashSettlement::class, 'target_bank_id');
    }
}
