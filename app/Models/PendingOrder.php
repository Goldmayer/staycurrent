<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PendingOrder extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'symbol_code',
        'timeframe_code',
        'side',
        'entry_price',
        'meta',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'meta' => 'array',
        'entry_price' => 'decimal:8',
    ];

    /**
     * Get the symbol associated with the pending order.
     */
    public function symbol()
    {
        return $this->belongsTo(Symbol::class, 'symbol_code', 'code');
    }
}
