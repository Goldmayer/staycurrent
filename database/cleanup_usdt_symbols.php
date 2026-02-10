<?php

use Illuminate\Support\Facades\DB;

// Set is_active = false for all symbols NOT ending with 'USDT'
DB::table('symbols')
    ->where('code', 'not like', '%USDT')
    ->update(['is_active' => false]);

echo "Set is_active = false for all non-USDT symbols.\n";
