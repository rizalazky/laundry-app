<?php

namespace App\Actions\Memberships;

use App\Models\Customer;
use App\Models\CustomerMembership;
use App\Models\MemberTier;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RegisterCustomerMembership
{
    public function handle(User $actor, int $customerId, int $memberTierId, ?Carbon $startedAt = null): CustomerMembership
    {
        if (
            ! $actor->is_active
            || $actor->tenant_id === null
            || ! in_array($actor->role, ['tenant_owner', 'branch_manager'], true)
            || ! $actor->hasPermissionTo('memberships.manage')
        ) {
            throw new AuthorizationException('Anda tidak memiliki izin untuk mendaftarkan membership pelanggan.');
        }

        return DB::transaction(function () use ($actor, $customerId, $memberTierId, $startedAt): CustomerMembership {
            $customer = Customer::query()
                ->where('tenant_id', $actor->tenant_id)
                ->lockForUpdate()
                ->find($customerId);

            if (! $customer) {
                throw ValidationException::withMessages([
                    'customer_id' => 'Pelanggan tidak ditemukan pada tenant ini.',
                ]);
            }

            $tier = MemberTier::query()
                ->where('tenant_id', $actor->tenant_id)
                ->where('is_active', true)
                ->lockForUpdate()
                ->find($memberTierId);

            if (! $tier) {
                throw ValidationException::withMessages([
                    'member_tier_id' => 'Jenis member tidak tersedia atau sudah tidak aktif.',
                ]);
            }

            $startsAt = ($startedAt ?? now())->copy();

            CustomerMembership::query()
                ->where('tenant_id', $actor->tenant_id)
                ->where('customer_id', $customer->id)
                ->where('status', CustomerMembership::STATUS_ACTIVE)
                ->where('expired_at', '<=', $startsAt)
                ->update([
                    'status' => CustomerMembership::STATUS_EXPIRED,
                    'updated_at' => now(),
                ]);

            $hasActiveMembership = CustomerMembership::query()
                ->where('tenant_id', $actor->tenant_id)
                ->where('customer_id', $customer->id)
                ->where('status', CustomerMembership::STATUS_ACTIVE)
                ->where('expired_at', '>', $startsAt)
                ->exists();

            if ($hasActiveMembership) {
                throw ValidationException::withMessages([
                    'customer_id' => 'Pelanggan masih memiliki membership aktif. Tunggu masa berlaku berakhir sebelum mendaftarkan membership baru.',
                ]);
            }

            return CustomerMembership::query()->create([
                'tenant_id' => $actor->tenant_id,
                'customer_id' => $customer->id,
                'member_tier_id' => $tier->id,
                'started_at' => $startsAt,
                'expired_at' => $startsAt->copy()->addDays($tier->duration_days),
                'status' => CustomerMembership::STATUS_ACTIVE,
            ]);
        });
    }
}
