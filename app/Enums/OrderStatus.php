<?php

namespace App\Enums;

enum OrderStatus: string
{
    case Received = 'received';
    case Washing = 'washing';
    case Drying = 'drying';
    case Ironing = 'ironing';
    case Packing = 'packing';
    case Ready = 'ready';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Received => 'Diterima',
            self::Washing => 'Dicuci',
            self::Drying => 'Dikeringkan',
            self::Ironing => 'Disetrika',
            self::Packing => 'Dikemas',
            self::Ready => 'Siap Diambil',
            self::Completed => 'Selesai',
            self::Cancelled => 'Dibatalkan',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Received => 'gray',
            self::Washing, self::Drying, self::Ironing, self::Packing => 'info',
            self::Ready => 'warning',
            self::Completed => 'success',
            self::Cancelled => 'danger',
        };
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Completed, self::Cancelled], true);
    }

    /**
     * Progress stages are forward-only and may skip steps (for example a
     * wash-only order). Completion is only possible from Ready.
     */
    public function canMoveTo(self $next): bool
    {
        if ($this->isFinal() || $this === $next) {
            return false;
        }

        if ($next === self::Cancelled) {
            return true;
        }

        if ($next === self::Completed) {
            return $this === self::Ready;
        }

        return $this->rank() < $next->rank();
    }

    public function canBeSetByRole(string $role): bool
    {
        return match ($this) {
            self::Cancelled => in_array($role, ['tenant_owner', 'branch_manager'], true),
            self::Completed => in_array($role, ['tenant_owner', 'branch_manager', 'cashier'], true),
            default => in_array($role, ['tenant_owner', 'branch_manager', 'cashier', 'operator'], true),
        };
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

    private function rank(): int
    {
        return match ($this) {
            self::Received => 0,
            self::Washing => 1,
            self::Drying => 2,
            self::Ironing => 3,
            self::Packing => 4,
            self::Ready => 5,
            self::Completed => 6,
            self::Cancelled => 7,
        };
    }
}
