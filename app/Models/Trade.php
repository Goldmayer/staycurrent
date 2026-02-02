<?php

namespace App\Models;

use App\Enums\TradeStatus;
use Illuminate\Database\Eloquent\Model;

class Trade extends Model
{
    protected $fillable = [
        'symbol_code',
        'timeframe_code',
        'side',
        'status',
        'opened_at',
        'closed_at',
        'entry_price',
        'exit_price',
        'realized_points',
        'unrealized_points',
        'meta',
    ];

    protected $casts = [
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'meta' => 'array',
    ];

    public function isOpen(): bool
    {
        return $this->status === TradeStatus::OPEN->value;
    }

    public function isClosed(): bool
    {
        return $this->status === TradeStatus::CLOSED->value;
    }
}
