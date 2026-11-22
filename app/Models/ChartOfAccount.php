<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChartOfAccount extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id',
        'code',
        'name',
        'account_type',
        'is_system',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function journalEntries(): HasMany
    {
        return $this->hasMany(JournalEntry::class, 'coa_id');
    }

    public function bankAccounts(): HasMany
    {
        return $this->hasMany(BankAccount::class, 'coa_id');
    }
}
