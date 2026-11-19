<?php

namespace App\Enums;

enum ServiceUnit: string
{
    case Kilogram = 'kg';
    case Piece = 'pcs';
    case SquareMeter = 'm2';

    public function label(): string
    {
        return match ($this) {
            self::Kilogram => 'Kiloan (per kg)',
            self::Piece => 'Satuan (per pcs)',
            self::SquareMeter => 'Meteran (per m²)',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $unit) {
            $options[$unit->value] = $unit->label();
        }

        return $options;
    }
}
