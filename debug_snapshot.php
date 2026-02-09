<?php

require 'vendor/autoload.php';

$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "===== CONFIG TRADING POINTS =====\n";
echo json_encode(config('trading.points'), JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) . "\n";

echo "\n===== SYMBOL POINT SIZES =====\n";
$symbols = ['BTCUSDT','ETHUSDT','BNBUSDT','GBPUSDT'];
foreach($symbols as $code) {
    $symbol = App\Models\Symbol::query()->where('code', $code)->first();
    echo $code . ' point_size=' . ($symbol?->point_size ?? 'NULL') . "\n";
}
