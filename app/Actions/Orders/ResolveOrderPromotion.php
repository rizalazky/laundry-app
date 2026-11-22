<?php

namespace App\Actions\Orders;

use App\Models\Promo;
use App\Models\Voucher;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DomainException;

class ResolveOrderPromotion
{
    /**
     * @return array{promo: Promo, voucher: Voucher, discount: BigDecimal}
     *
     * @throws DomainException
     */
    public function handle(int $tenantId, string $voucherCode, BigDecimal $subtotal): array
    {
        $code = strtoupper(trim($voucherCode));

        if ($code === '') {
            throw new DomainException('Kode voucher wajib diisi.');
        }

        $voucher = Voucher::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->whereRaw('UPPER(code) = ?', [$code])
            ->lockForUpdate()
            ->first();

        if ($voucher === null || ! $voucher->is_active) {
            throw new DomainException('Voucher tidak ditemukan atau tidak aktif.');
        }

        if (
            ($voucher->starts_at !== null && $voucher->starts_at->isFuture())
            || ($voucher->expires_at !== null && $voucher->expires_at->isPast())
        ) {
            throw new DomainException('Voucher belum berlaku atau sudah kedaluwarsa.');
        }

        if ($voucher->voucher_type !== Voucher::TYPE_GENERAL) {
            throw new DomainException('Voucher referral belum dapat digunakan pada checkout umum.');
        }

        if ($voucher->quota !== null && $voucher->used_count >= $voucher->quota) {
            throw new DomainException('Kuota voucher sudah habis.');
        }

        if ($voucher->promo_id === null) {
            throw new DomainException('Voucher ini belum terhubung dengan promo.');
        }

        $promo = Promo::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->whereKey($voucher->promo_id)
            ->lockForUpdate()
            ->first();

        if ($promo === null || ! $promo->is_active) {
            throw new DomainException('Promo untuk voucher ini tidak aktif.');
        }

        if (
            ($promo->start_at !== null && $promo->start_at->isFuture())
            || ($promo->end_at !== null && $promo->end_at->isPast())
        ) {
            throw new DomainException('Promo belum berlaku atau sudah berakhir.');
        }

        $unsupportedRules = array_diff(array_keys($promo->rules ?? []), ['minimum_order']);

        if ($unsupportedRules !== []) {
            throw new DomainException('Promo memiliki ketentuan yang belum didukung pada checkout.');
        }

        $minimumOrder = $promo->rules['minimum_order'] ?? null;

        if ($minimumOrder !== null && $subtotal->isLessThan(BigDecimal::of((string) $minimumOrder))) {
            throw new DomainException('Subtotal belum memenuhi minimum transaksi promo.');
        }

        $rewardValue = BigDecimal::of((string) $promo->reward_value)->toScale(2, RoundingMode::HalfUp);

        if ($promo->reward_type === Promo::REWARD_PERCENT) {
            if ($rewardValue->isLessThanOrEqualTo(0) || $rewardValue->isGreaterThan(100)) {
                throw new DomainException('Persentase promo harus lebih dari 0 dan maksimal 100%.');
            }

            $discount = $subtotal
                ->multipliedBy($rewardValue)
                ->dividedBy(100, 2, RoundingMode::HalfUp);
        } elseif ($promo->reward_type === Promo::REWARD_FIXED) {
            if ($rewardValue->isLessThanOrEqualTo(0)) {
                throw new DomainException('Nominal promo harus lebih dari 0.');
            }

            $discount = $rewardValue->isGreaterThan($subtotal) ? $subtotal : $rewardValue;
        } else {
            throw new DomainException('Jenis benefit promo tidak didukung.');
        }

        return [
            'promo' => $promo,
            'voucher' => $voucher,
            'discount' => $discount->toScale(2, RoundingMode::HalfUp),
        ];
    }
}
