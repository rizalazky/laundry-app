<?php

namespace App\\Models;

use App\\Models\\Concerns\\BelongsToTenant;
use Illuminate\\Database\\Eloquent\\Factories\\HasFactory;
use Illuminate\\Database\\Eloquent\\Model;
use Illuminate\\Database\\Eloquent\\Relations\\BelongsTo;

class CashSettlement extends Model
{
    use BelongsToTenant, HasFactory;

    public const SOURCE_OUTLET_CASH = 'outlet_cash';

    public const SOURCE_WORKSHOP_CASH = 'workshop_cash';

    public const SOURCE_EDC = 'edc';

    protected $fillable = [
        'tenant_id',
        'branch_id',
        'source_type',
        'amount',
        'gross_amount',
        'mdr_amount',
        'net_amount',
        'edc_machine_id',
        'target_bank_id',
        'reference_number',
        'settled_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'gross_amount' => 'decimal:2',
            'mdr_amount' => 'decimal:2',
            'net_amount' => 'decimal:2',
            'settled_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function edcMachine(): BelongsTo
    {
        return $this->belongsTo(EdcMachine::class);
    }

    public function targetBank(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'target_bank_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class, 'journal_id');
    }
}
