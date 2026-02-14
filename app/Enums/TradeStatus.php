<?php

namespace App\Enums;

enum TradeStatus: string
{
    case OPEN = 'open';
    case CLOSED = 'closed';
    case PENDING = 'pending';
    case CANCELLED = 'cancelled';

    public static function options(): array
    {
        return [
            self::OPEN->value => self::OPEN->value,
            self::CLOSED->value => self::CLOSED->value,
            self::PENDING->value => self::PENDING->value,
            self::CANCELLED->value => self::CANCELLED->value,
        ];
    }
}
