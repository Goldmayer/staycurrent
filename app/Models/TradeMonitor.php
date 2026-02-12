<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TradeMonitor extends Model
{
    protected $fillable = [
        'symbol_code',
        'timeframe_code',
        'expectation',
        'open_trade_id',
        'last_notified_state',
    ];

    protected $casts = [
        'expectation' => 'string',
        'open_trade_id' => 'integer',
    ];

    public function openTrade(): BelongsTo
    {
        return $this->belongsTo(Trade::class, 'open_trade_id');
    }
}
