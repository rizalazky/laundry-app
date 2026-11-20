<?php

namespace App\Enums;

use Brick\Math\BigDecimal;

enum PaymentStatus: string
{
    case Unpaid = 'unpaid';
    case Partial = 'partial';
    case Paid = 'paid';

    public function label(): string
    {
        return match ($this) {
            self::Unpaid => 'Belum Bayar',
            self::Partial => 'DP / Sebagian',
            self::Paid => 'Lunas',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Unpaid => 'danger',
            self::Partial => 'warning',
            self::Paid => 'success',
        };
    }

    public static function fromAmounts(BigDecimal|string|int $total, BigDecimal|string|int $paid): self
    {
        $paid = BigDecimal::of($paid);

        if ($paid->isLessThanOrEqualTo(0) && BigDecimal::of($total)->isGreaterThan(0)) {
            return self::Unpaid;
        }

        return $paid->isGreaterThanOrEqualTo($total) ? self::Paid : self::Partial;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $status) {
            $options[$status->value] = $status->label();
        }

        return $options;
    }
}
