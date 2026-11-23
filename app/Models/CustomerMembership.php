<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerMembership extends Model
{
    use BelongsToTenant, HasFactory;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_REPLACED = 'replaced';

    protected $fillable = [
        'tenant_id',
        'customer_id',
        'member_tier_id',
        'started_at',
        'expired_at',
        'status',
        'activated_by',
        'activated_at',
        'activated_at_branch_id',
        'replaced_by_membership_id',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'expired_at' => 'datetime',
            'activated_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function memberTier(): BelongsTo
    {
        return $this->belongsTo(MemberTier::class);
    }

    public function activatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'activated_by');
    }

    public function activatedAtBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'activated_at_branch_id');
    }

    public function replacedByMembership(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaced_by_membership_id');
    }
}
