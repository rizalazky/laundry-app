<?php

namespace App\\Enums;

enum StockMovementType: string
{
    case In = 'in';
    case Out = 'out';
    case Purchase = 'purchase';
    case Usage = 'usage';
    case Adjustment = 'adjustment';

    public function label(): string
    {
        return match ($this) {
            self::In => 'Stok Masuk',
            self::Out => 'Stok Keluar',
            self::Purchase => 'Pembelian',
            self::Usage => 'Penggunaan',
            self::Adjustment => 'Penyesuaian',
        };
    }
}
