<?php

namespace App\Enums;

enum CourierTaskType: string
{
    case Pickup = 'pickup';
    case Delivery = 'delivery';

    public function label(): string
    {
        return match ($this) {
            self::Pickup => 'Pickup / Penjemputan',
            self::Delivery => 'Delivery / Pengantaran',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return [
            self::Pickup->value => self::Pickup->label(),
            self::Delivery->value => self::Delivery->label(),
        ];
    }
}
