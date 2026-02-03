<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Candle extends Model
{
    use HasFactory;

    protected $fillable = [
        'symbol_code',
        'timeframe_code',
        'open_time_ms',
        'open',
        'high',
        'low',
        'close',
        'volume',
        'close_time_ms',
    ];

    protected $casts = [
        'open_time_ms' => 'integer',
        'open' => 'decimal:8',
        'high' => 'decimal:8',
        'low' => 'decimal:8',
        'close' => 'decimal:8',
        'volume' => 'decimal:8',
        'close_time_ms' => 'integer',
    ];

    public function symbol()
    {
        return $this->belongsTo(Symbol::class, 'symbol_code', 'code');
    }
}
