<?php

namespace App\Enums;

enum OrderSource: string
{
    case Pos = 'pos';
    case Omnichannel = 'omnichannel';
    case LoyaltyApp = 'loyalty_app';

    public function label(): string
    {
        return match ($this) {
            self::Pos => 'POS',
            self::Omnichannel => 'Omnichannel',
            self::LoyaltyApp => 'Loyalty App',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $source) {
            $options[$source->value] = $source->label();
        }

        return $options;
    }
}
