<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Symbol extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'is_active',
        'sort',
        'point_size',
        'price_decimals',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort' => 'integer',
        'point_size' => 'decimal:8',
        'price_decimals' => 'integer',
    ];

    public function quotes()
    {
        return $this->hasOne(SymbolQuote::class, 'symbol_code', 'code');
    }

    public function candles()
    {
        return $this->hasMany(Candle::class, 'symbol_code', 'code');
    }
}
