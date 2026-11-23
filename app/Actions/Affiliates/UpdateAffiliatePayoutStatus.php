<?php

namespace App\Actions\Affiliates;

use App\Models\AffiliatePayout;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateAffiliatePayoutStatus
{
    public function handle(User $user, int $payoutId, string $nextStatus): AffiliatePayout
    {
        if (! $user->is_active || $user->role !== 'tenant_owner' || ! $user->hasPermissionTo('affiliates.manage')) {
            throw ValidationException::withMessages([
                'payout' => 'Kamu tidak memiliki akses untuk mengelola payout afiliasi.',
            ]);
        }

        if (! in_array($nextStatus, [AffiliatePayout::STATUS_PAID, AffiliatePayout::STATUS_CANCELLED], true)) {
            throw ValidationException::withMessages([
                'status' => 'Status payout tujuan tidak valid.',
            ]);
        }

        return DB::transaction(function () use ($user, $payoutId, $nextStatus): AffiliatePayout {
            $payout = AffiliatePayout::query()
                ->where('tenant_id', $user->tenant_id)
                ->lockForUpdate()
                ->find($payoutId);

            if (! $payout) {
                throw ValidationException::withMessages([
                    'payout' => 'Payout tidak ditemukan pada tenant ini.',
                ]);
            }

            if ($payout->status !== AffiliatePayout::STATUS_PENDING) {
                throw ValidationException::withMessages([
                    'status' => 'Hanya payout yang masih menunggu pembayaran yang dapat diubah.',
                ]);
            }

            $payout->update(['status' => $nextStatus]);

            return $payout->refresh();
        });
    }
}
