<?php

namespace App\Console\Commands;

use App\Models\Symbol;
use App\Services\MarketData\MarketDataSyncService;
use Illuminate\Console\Command;

class MarketSync extends Command
{
    protected $signature = 'market:sync
                            {--symbol= : Sync only specific symbol code}
                            {--only-quotes : Sync only quotes, skip candles}
                            {--only-candles : Sync only candles, skip quotes}
                            {--limit=200 : Limit for kline data}';

    protected $description = 'Sync market data (price-only)';

    public function handle(MarketDataSyncService $syncService): int
    {
        $symbolCode = $this->option('symbol');
        $onlyCandles = (bool) $this->option('only-candles');

        if ($onlyCandles) {
            $this->info('Candles syncing is disabled in price-only mode.');
            return 0;
        }

        if ($symbolCode) {
            $this->syncSingleSymbol($syncService, $symbolCode);
            return 0;
        }

        $this->syncAllSymbols($syncService);

        return 0;
    }

    private function syncSingleSymbol(MarketDataSyncService $syncService, string $symbolCode): void
    {
        $this->info("Syncing symbol: {$symbolCode}");

        $syncService->syncSymbolQuote($symbolCode);

        $this->info('  ✓ Quotes synced');
    }

    private function syncAllSymbols(MarketDataSyncService $syncService): void
    {
        $symbols = Symbol::query()
                         ->where('is_active', true)
                         ->orderBy('sort')
                         ->orderBy('code')
                         ->pluck('code')
                         ->all();

        $this->info('Syncing ' . count($symbols) . ' active symbols...');

        foreach ($symbols as $code) {
            $syncService->syncSymbolQuote($code);
        }

        $this->info('  ✓ Quotes synced');
    }
}
