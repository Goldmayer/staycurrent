<?php

namespace App\Enums;

enum TradeStatus: string
{
    case OPEN = 'open';
    case CLOSED = 'closed';

    public static function options(): array
    {
        return [
            self::OPEN->value => self::OPEN->value,
            self::CLOSED->value => self::CLOSED->value,
        ];
    }
}
