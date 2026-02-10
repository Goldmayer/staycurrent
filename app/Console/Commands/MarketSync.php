<?php

namespace App\Console\Commands;

use App\Models\Symbol;
use App\Services\MarketData\MarketDataSyncService;
use App\Services\Trading\FxSessionScheduler;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class MarketSync extends Command
{
    protected $signature = 'market:sync
                            {--symbol= : Sync only specific symbol code}
                            {--only-quotes : Sync only quotes, skip candles}
                            {--only-candles : Sync only candles, skip quotes}
                            {--limit=200 : Limit for kline data}';

    protected $description = 'Sync market data (price-only)';

    public function __construct(
        private readonly FxSessionScheduler $scheduler,
        private readonly MarketDataSyncService $syncService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $symbolCode = $this->option('symbol');
        $onlyCandles = (bool) $this->option('only-candles');

        if ($onlyCandles) {
            $this->info('Candles syncing is disabled in price-only mode.');
            return 0;
        }

        if ($symbolCode) {
            $this->syncSingleSymbol((string) $symbolCode);
            return 0;
        }

        $this->syncAllSymbols();

        return 0;
    }

    private function syncSingleSymbol(string $symbolCode): void
    {
        $this->info("Syncing symbol: {$symbolCode}");

        $this->syncService->syncSymbolQuote($symbolCode);

        $this->info('  ✓ Quotes synced');
    }

    private function syncAllSymbols(): void
    {
        $symbols = Symbol::query()
                         ->where('is_active', true)
                         ->orderBy('sort')
                         ->orderBy('code')
                         ->pluck('code')
                         ->all();

        $this->info('Syncing ' . count($symbols) . ' active symbols...');

        $lastQuotes = \App\Models\SymbolQuote::query()
                                             ->whereIn('symbol_code', $symbols)
                                             ->get(['symbol_code', 'pulled_at', 'updated_at'])
                                             ->keyBy('symbol_code');

        $openTradeSymbols = \App\Models\Trade::query()
                                             ->where('status', \App\Enums\TradeStatus::OPEN->value)
                                             ->pluck('symbol_code')
                                             ->unique()
                                             ->flip();

        $synced = 0;
        $skipped = 0;

        $now = Carbon::now();

        foreach ($symbols as $code) {
            $hasOpenTrade = isset($openTradeSymbols[$code]);
            $lastQuote = $lastQuotes[$code] ?? null;
            $lastPulledAt = $lastQuote?->pulled_at ?? $lastQuote?->updated_at;

            $interval = $this->scheduler->syncIntervalMinutes($code, $now, $hasOpenTrade);

            if ($this->scheduler->isQuoteDue($lastPulledAt, $interval, $now)) {
                $this->syncService->syncSymbolQuote($code);
                $synced++;
            } else {
                $skipped++;
            }
        }

        $this->info("  ✓ Quotes synced: {$synced}, skipped: {$skipped}");
    }
}
