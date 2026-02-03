<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SymbolQuote extends Model
{
    use HasFactory;

    protected $fillable = [
        'symbol_code',
        'price',
        'source',
    ];

    protected $casts = [
        'price' => 'decimal:8',
    ];

    public function symbol()
    {
        return $this->belongsTo(Symbol::class, 'symbol_code', 'code');
    }
}
