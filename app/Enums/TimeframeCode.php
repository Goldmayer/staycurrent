<?php

namespace App\Enums;

enum TimeframeCode: string
{
    case M5 = '5m';
    case M15 = '15m';
    case M30 = '30m';
    case H1 = '1h';
    case H4 = '4h';
    case D1 = '1d';
}
