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
        'status' => \App\Enums\TradeStatus::class,
    ];

    public function isLong(): bool
    {
        return $this->side === 'buy';
    }

    public function isShort(): bool
    {
        return $this->side === 'sell';
    }

    public function isOpen(): bool
    {
        if ($this->status instanceof \App\Enums\TradeStatus) {
            return $this->status === TradeStatus::OPEN;
        }
        return (string) $this->status === TradeStatus::OPEN->value;
    }

    public function isClosed(): bool
    {
        if ($this->status instanceof \App\Enums\TradeStatus) {
            return $this->status === TradeStatus::CLOSED;
        }
        return (string) $this->status === TradeStatus::CLOSED->value;
    }
}
