<?php

namespace App\Actions\Memberships;

use App\Actions\Orders\OrderActionAuthorization;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerMembership;
use App\Models\MemberTier;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RegisterCustomerMembership
{
    public function handle(
        User $actor,
        Branch $branch,
        int $customerId,
        int $memberTierId,
        ?Carbon $startedAt = null,
        bool $paymentConfirmed = false,
        bool $confirmReplacement = false,
    ): CustomerMembership {
        if (! $paymentConfirmed) {
            throw ValidationException::withMessages([
                'payment_confirmed' => 'Konfirmasi bahwa pembayaran membership telah diterima sebelum aktivasi.',
            ]);
        }

        if (Auth::id() === null || (int) Auth::id() !== (int) $actor->getKey()) {
            throw new AuthorizationException('Anda tidak memiliki izin untuk mengaktifkan membership pelanggan.');
        }

        $canonicalActor = User::query()->find($actor->getKey());

        if (! $canonicalActor) {
            throw new AuthorizationException('Akun pengguna tidak ditemukan.');
        }

        $authorizedBranch = app(OrderActionAuthorization::class)->authorizeBranch(
            $canonicalActor,
            $branch,
            ['tenant_owner', 'branch_manager', 'cashier'],
        );

        if (! $canonicalActor->hasPermissionTo('memberships.manage')) {
            throw new AuthorizationException('Anda tidak memiliki izin untuk mengaktifkan membership pelanggan.');
        }

        return DB::transaction(function () use (
            $canonicalActor,
            $authorizedBranch,
            $customerId,
            $memberTierId,
            $startedAt,
            $confirmReplacement,
        ): CustomerMembership {
            $customer = Customer::query()
                ->where('tenant_id', $canonicalActor->tenant_id)
                ->lockForUpdate()
                ->find($customerId);

            if (! $customer) {
                throw ValidationException::withMessages([
                    'customer_id' => 'Pelanggan tidak ditemukan pada tenant ini.',
                ]);
            }

            $tier = MemberTier::query()
                ->where('tenant_id', $canonicalActor->tenant_id)
                ->where('is_active', true)
                ->lockForUpdate()
                ->find($memberTierId);

            if (! $tier) {
                throw ValidationException::withMessages([
                    'member_tier_id' => 'Jenis member tidak tersedia atau sudah tidak aktif.',
                ]);
            }

            $activatedAt = now();
            $startsAt = ($startedAt ?? $activatedAt)->copy();

            if ($startsAt->greaterThan($activatedAt)) {
                throw ValidationException::withMessages([
                    'started_at' => 'Membership tidak dapat diaktifkan dengan tanggal mulai di masa depan.',
                ]);
            }

            CustomerMembership::query()
                ->where('tenant_id', $canonicalActor->tenant_id)
                ->where('customer_id', $customer->id)
                ->where('status', CustomerMembership::STATUS_ACTIVE)
                ->where('expired_at', '<=', $activatedAt)
                ->update([
                    'status' => CustomerMembership::STATUS_EXPIRED,
                    'updated_at' => $activatedAt,
                ]);

            $activeMembership = CustomerMembership::query()
                ->where('tenant_id', $canonicalActor->tenant_id)
                ->where('customer_id', $customer->id)
                ->where('status', CustomerMembership::STATUS_ACTIVE)
                ->where('started_at', '<=', $activatedAt)
                ->where('expired_at', '>', $activatedAt)
                ->lockForUpdate()
                ->with('memberTier')
                ->first();

            if ($activeMembership && ! $confirmReplacement) {
                $tierName = $activeMembership->memberTier?->name ?? 'membership aktif';

                throw ValidationException::withMessages([
                    'confirm_replacement' => "Customer ini masih memiliki membership {$tierName}. Jika dilanjutkan, membership {$tierName} akan digantikan dengan {$tier->name}. Konfirmasi penggantian untuk melanjutkan.",
                ]);
            }

            $newMembership = CustomerMembership::query()->create([
                'tenant_id' => $canonicalActor->tenant_id,
                'customer_id' => $customer->id,
                'member_tier_id' => $tier->id,
                'started_at' => $startsAt,
                'expired_at' => $startsAt->copy()->addDays($tier->duration_days),
                'status' => CustomerMembership::STATUS_ACTIVE,
                'activated_by' => $canonicalActor->getKey(),
                'activated_at' => $activatedAt,
                'activated_at_branch_id' => $authorizedBranch->getKey(),
            ]);

            if ($activeMembership) {
                $activeMembership->forceFill([
                    'status' => CustomerMembership::STATUS_REPLACED,
                    'replaced_by_membership_id' => $newMembership->getKey(),
                ])->save();
            }

            return $newMembership->load(['memberTier', 'activatedBy', 'activatedAtBranch']);
        });
    }
}
