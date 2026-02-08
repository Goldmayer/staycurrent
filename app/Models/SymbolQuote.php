<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SymbolQuote extends Model
{
    protected $fillable = [
        'symbol_code',
        'price',
        'source',
        'pulled_at',
        'updated_at',
    ];

    protected $casts = [
        'price' => 'decimal:8',
        'pulled_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
    protected $guarded = [];


    public function symbol()
    {
        return $this->belongsTo(Symbol::class, 'symbol_code', 'code');
    }
}
