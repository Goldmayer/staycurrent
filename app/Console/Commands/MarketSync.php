<?php

namespace App\Console\Commands;

use App\Services\MarketData\MarketDataSyncService;
use Illuminate\Console\Command;

class MarketSync extends Command
{
    protected $signature = 'market:sync
                            {--symbol= : Sync only specific symbol code}
                            {--only-quotes : Sync only quotes, skip candles}
                            {--only-candles : Sync only candles, skip quotes}
                            {--limit=200 : Limit for kline data}';

    protected $description = 'Sync market data from Binance';

    public function handle(MarketDataSyncService $syncService): int
    {
        $symbolCode = $this->option('symbol');
        $onlyQuotes = $this->option('only-quotes');
        $onlyCandles = $this->option('only-candles');
        $limit = (int) $this->option('limit');

        if ($symbolCode) {
            $this->syncSingleSymbol($syncService, $symbolCode, $onlyQuotes, $onlyCandles, $limit);
        } else {
            $this->syncAllSymbols($syncService, $onlyQuotes, $onlyCandles, $limit);
        }

        return 0;
    }

    private function syncSingleSymbol(MarketDataSyncService $syncService, string $symbolCode, bool $onlyQuotes, bool $onlyCandles, int $limit): void
    {
        $this->info("Syncing symbol: {$symbolCode}");

        if (!$onlyCandles) {
            $syncService->syncSymbolQuote($symbolCode);
            $this->info("  ✓ Quotes synced");
        }

        if (!$onlyQuotes) {
            $syncService->syncSymbolCandles($symbolCode, $limit);
            $this->info("  ✓ Candles synced");
        }
    }

    private function syncAllSymbols(MarketDataSyncService $syncService, bool $onlyQuotes, bool $onlyCandles, int $limit): void
    {
        $symbols = \App\Models\Symbol::where('is_active', true)
            ->orderBy('sort')
            ->orderBy('code')
            ->get();

        $this->info("Syncing {$symbols->count()} active symbols...");

        foreach ($symbols as $symbol) {
            $this->line("{$symbol->code}:");
            $this->syncSingleSymbol($syncService, $symbol->code, $onlyQuotes, $onlyCandles, $limit);
        }
    }
}
