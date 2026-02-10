<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PriceTick extends Model
{
    use HasFactory;

    protected $fillable = [
        'symbol_code',
        'price',
        'pulled_at',
    ];

    protected $casts = [
        'pulled_at' => 'datetime',
    ];
}
