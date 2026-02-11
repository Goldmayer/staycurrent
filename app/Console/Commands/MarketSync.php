<?php

namespace App\Console\Commands;

use App\Models\Symbol;
use App\Services\MarketData\MarketDataSyncService;
use App\Services\Trading\FxSessionScheduler;
use App\Services\Trading\TradeTickService;
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
        private readonly TradeTickService $tradeTickService,
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
        $symbolCode = strtoupper(trim($symbolCode));

        $this->info("Syncing symbol: {$symbolCode}");

        $ok = $this->syncService->syncSymbolQuote($symbolCode);

        // ✅ Один вызов, даже для одного символа (batch API)
        if ($ok) {
            $this->tradeTickService->processSymbols(
                symbolCodes: [$symbolCode],
                forceOpen: false,
                forceSide: null,
                forceTimeframe: null,
            );
        }

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

        /** @var array<int, string> $updatedSymbols */
        $updatedSymbols = [];

        foreach ($symbols as $code) {
            $code = strtoupper(trim((string) $code));
            if ($code === '') {
                $skipped++;
                continue;
            }

            $hasOpenTrade = isset($openTradeSymbols[$code]);
            $lastQuote = $lastQuotes[$code] ?? null;
            $lastPulledAt = $lastQuote?->pulled_at ?? $lastQuote?->updated_at;

            $interval = $this->scheduler->syncIntervalMinutes($code, $now, $hasOpenTrade);

            if ($this->scheduler->isQuoteDue($lastPulledAt, $interval, $now)) {
                $ok = $this->syncService->syncSymbolQuote($code);
                if ($ok) {
                    $updatedSymbols[] = $code;
                }
                $synced++;
            } else {
                $skipped++;
            }
        }

        // ✅ ОДИН вызов торгового тика по всем обновлённым символам
        $updatedSymbols = array_values(array_unique($updatedSymbols));

        if ($updatedSymbols !== []) {
            $this->tradeTickService->processSymbols(
                symbolCodes: $updatedSymbols,
                forceOpen: false,
                forceSide: null,
                forceTimeframe: null,
            );
        }

        $this->info("  ✓ Quotes synced: {$synced}, skipped: {$skipped}");
    }
}
