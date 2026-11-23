<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LoyaltyPointRule extends Model
{
    use BelongsToTenant;

    public const TARGET_ALL_CUSTOMERS = 'all_customers';
    public const TARGET_ALL_MEMBERS = 'all_members';
    public const TARGET_MEMBER_TIER = 'member_tier';

    protected $fillable = [
        'tenant_id', 'branch_id', 'name', 'target_type', 'member_tier_id',
        'amount_step', 'points_per_step', 'starts_at', 'ends_at', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'amount_step' => 'decimal:2',
            'points_per_step' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $rule): void {
            if (! in_array($rule->target_type, [
                self::TARGET_ALL_CUSTOMERS, self::TARGET_ALL_MEMBERS, self::TARGET_MEMBER_TIER,
            ], true)) {
                throw ValidationException::withMessages(['target_type' => 'Target program poin tidak valid.']);
            }

            if ($rule->target_type === self::TARGET_MEMBER_TIER && $rule->member_tier_id === null) {
                throw ValidationException::withMessages(['member_tier_id' => 'Pilih jenis member untuk target program ini.']);
            }

            if ($rule->target_type !== self::TARGET_MEMBER_TIER) {
                $rule->member_tier_id = null;
            }

            if ((float) $rule->amount_step <= 0 || (int) $rule->points_per_step < 1) {
                throw ValidationException::withMessages(['amount_step' => 'Nilai kelipatan dan poin harus lebih besar dari nol.']);
            }

            if ($rule->starts_at === null || $rule->ends_at === null || $rule->ends_at->lessThanOrEqualTo($rule->starts_at)) {
                throw ValidationException::withMessages(['ends_at' => 'Tanggal akhir harus setelah tanggal mulai.']);
            }

            DB::transaction(function () use ($rule): void {
                $branch = Branch::query()->where('tenant_id', $rule->tenant_id)
                    ->lockForUpdate()->find($rule->branch_id);

                if ($branch === null || $branch->is_workshop || ! $branch->is_active) {
                    throw ValidationException::withMessages(['branch_id' => 'Pilih outlet aktif dari tenant ini, bukan workshop.']);
                }

                if ($rule->target_type === self::TARGET_MEMBER_TIER) {
                    $tierExists = MemberTier::query()->where('tenant_id', $rule->tenant_id)
                        ->where('is_active', true)->whereKey($rule->member_tier_id)->exists();

                    if (! $tierExists) {
                        throw ValidationException::withMessages(['member_tier_id' => 'Jenis member tidak valid atau tidak aktif.']);
                    }
                }

                $overlap = self::query()
                    ->where('tenant_id', $rule->tenant_id)
                    ->where('branch_id', $rule->branch_id)
                    ->where('target_type', $rule->target_type)
                    ->where('member_tier_id', $rule->member_tier_id)
                    ->where('starts_at', '<', $rule->ends_at)
                    ->where('ends_at', '>', $rule->starts_at)
                    ->when($rule->exists, fn (Builder $query) => $query->where('id', '<>', $rule->getKey()))
                    ->exists();

                if ($overlap) {
                    throw ValidationException::withMessages([
                        'starts_at' => 'Sudah ada program poin dengan target dan outlet yang sama pada periode yang beririsan.',
                    ]);
                }
            });
        });
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function memberTier(): BelongsTo
    {
        return $this->belongsTo(MemberTier::class);
    }

    public function pointLogs(): HasMany
    {
        return $this->hasMany(PointLog::class);
    }
}
