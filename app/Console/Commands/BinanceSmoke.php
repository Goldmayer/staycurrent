<?php

namespace App\Console\Commands;

use App\Services\MarketData\BinanceMarketDataClient;
use Illuminate\Console\Command;

class BinanceSmoke extends Command
{
    protected $signature = 'binance:smoke {symbol=BTCUSDT} {interval=5m} {--limit=10}';

    protected $description = 'Smoke test for Binance market data connectivity';

    public function handle(BinanceMarketDataClient $client): int
    {
        $symbol = $this->argument('symbol');
        $interval = $this->argument('interval');
        $limit = $this->option('limit');

        $this->info("Testing Binance connectivity for {$symbol} with {$interval} interval...");

        // Test lastPrice
        $this->line('Testing lastPrice...');
        $price = $client->lastPrice($symbol);
        if ($price !== null) {
            $this->info("  Last price: {$price}");
        } else {
            $this->error("  Failed to get last price");
        }

        // Test klines
        $this->line('Testing klines...');
        $klines = $client->klines($symbol, $interval, $limit);
        if (!empty($klines)) {
            $this->info("  Retrieved " . count($klines) . " kline records");
            $this->line("  First record: " . json_encode($klines[0], JSON_PRETTY_PRINT));
        } else {
            $this->error("  Failed to get kline data");
        }

        $this->info('Binance smoke test completed.');
        return 0;
    }
}
