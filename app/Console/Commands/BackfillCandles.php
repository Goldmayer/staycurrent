<?php

namespace App\Console\Commands;

use App\Services\MarketData\MarketDataSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillCandles extends Command
{
    protected $signature = 'candles:backfill {symbol? : Symbol code to backfill (optional)}';

    protected $description = 'Backfill last 200 candles for all timeframes (5m, 15m, 30m, 1h, 4h, 1d)';

    public function handle(MarketDataSyncService $syncService): int
    {
        $symbolCode = $this->argument('symbol');

        if ($symbolCode) {
            $this->info("Backfilling candles for symbol: {$symbolCode}");
            $this->backfillSymbol($syncService, $symbolCode);
        } else {
            $this->info('Backfilling candles for all active symbols...');
            $this->backfillAllSymbols($syncService);
        }

        $this->info('Backfill completed successfully!');

        return 0;
    }

    private function backfillSymbol(MarketDataSyncService $syncService, string $symbolCode): void
    {
        $timeframes = ['5m', '15m', '30m', '1h', '4h', '1d'];

        foreach ($timeframes as $timeframe) {
            $this->info("  Processing {$symbolCode} - {$timeframe}...");

            try {
                $syncService->syncSymbolCandles($symbolCode, 200);

                // Query actual count from DB
                $actualCount = DB::table('candles')
                    ->where('symbol_code', $symbolCode)
                    ->where('timeframe_code', $timeframe)
                    ->count();

                if ($actualCount > 0) {
                    $this->info("    ✓ {$timeframe} candles in DB: {$actualCount}");
                } else {
                    $this->warn("    ⚠ {$timeframe} returned 0 candles (nothing stored)");
                }
            } catch (\Exception $e) {
                $this->error("    ✗ {$timeframe} failed: {$e->getMessage()}");
            }

            // Sleep 1 second between API calls to be gentle on rate limits
            sleep(1);
        }
    }

    private function backfillAllSymbols(MarketDataSyncService $syncService): void
    {
        $symbols = \App\Models\Symbol::where('is_active', true)
            ->orderBy('sort')
            ->orderBy('code')
            ->get();

        $this->info("Found {$symbols->count()} active symbols");

        foreach ($symbols as $symbol) {
            $this->line("Processing {$symbol->code}...");

            $this->backfillSymbol($syncService, $symbol->code);

            // Additional sleep between symbols to be extra gentle
            sleep(1);
        }
    }
}
