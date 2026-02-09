<?php

namespace App\Services\MarketData;

use App\Contracts\FxQuotesProvider;
use App\Contracts\MarketDataProvider;
use App\Enums\TimeframeCode;
use App\Models\Symbol;
use App\Models\SymbolQuote;
use Illuminate\Support\Facades\DB;

class MarketDataSyncService
{
    private readonly MarketDataProvider $provider;
    private readonly FxQuotesProvider $fxQuotesProvider;

    public function __construct(MarketDataProvider $provider, FxQuotesProvider $fxQuotesProvider)
    {
        $this->provider = $provider;
        $this->fxQuotesProvider = $fxQuotesProvider;
    }

    public function syncSymbol(string $symbolCode): void
    {
        $this->syncSymbolQuote($symbolCode);
        $this->syncSymbolCandles($symbolCode);
    }

    public function syncSymbolQuote(string $symbolCode): void
    {
        try {
            $price = $this->provider->lastPrice($symbolCode);

            if ($price === null || $price <= 0) {
                throw new \RuntimeException("Provider lastPrice returned invalid value for {$symbolCode}");
            }

            SymbolQuote::updateOrCreate(
                ['symbol_code' => $symbolCode],
                [
                    'price' => $price,
                    'source' => $this->provider->source(),
                    'pulled_at' => now(),
                    'updated_at' => now(),
                ]
            );
        } catch (\Exception $e) {
            SymbolQuote::query()
                       ->where('symbol_code', $symbolCode)
                       ->update([
                           'source' => 'provider_error',
                           'pulled_at' => now(),
                           'updated_at' => now(),
                       ]);

            report($e);
        }
    }

    public function syncSymbolCandles(string $symbolCode, int $limit = 200): void
    {
        $symbol = Symbol::query()->where('code', $symbolCode)->first();

        if (!$symbol) {
            return;
        }

        $timeframes = $this->timeframesToSyncNow();

        foreach ($timeframes as $timeframeCode) {
            try {
                $klines = $this->provider->candles(
                    $symbolCode,
                    $timeframeCode,
                    $limit
                );

                $this->upsertCandles($symbolCode, $timeframeCode, $klines);
            } catch (\Exception $e) {
                report($e);
            }
        }
    }

    /**
     * Determine which timeframes should be synced based on current UTC time.
     *
     * Rules:
     * - Always include '5m'
     * - Include '15m' when current minute % 15 == 0
     * - Include '30m' when current minute % 30 == 0
     * - Include '1h' when current minute == 0
     * - Include '4h' when current hour % 4 == 0 AND current minute == 0
     * - Include '1d' when current hour == 0 AND current minute == 0
     *
     * @return array List of timeframe codes to sync
     */
    private function timeframesToSyncNow(): array
    {
        $now = now()->utc();
        $minute = $now->minute;
        $hour = $now->hour;

        $timeframes = ['5m'];

        if ($minute % 15 === 0) {
            $timeframes[] = '15m';
        }

        if ($minute % 30 === 0) {
            $timeframes[] = '30m';
        }

        if ($minute === 0) {
            $timeframes[] = '1h';

            if ($hour % 4 === 0) {
                $timeframes[] = '4h';
            }

            if ($hour === 0) {
                $timeframes[] = '1d';
            }
        }

        return $timeframes;
    }

    private function upsertCandles(string $symbolCode, string $timeframeCode, array $klines): void
    {
        if (empty($klines)) {
            return;
        }

        $now = now();
        $candles = [];

        foreach ($klines as $kline) {
            $candles[] = [
                'symbol_code' => $symbolCode,
                'timeframe_code' => $timeframeCode,
                'open_time_ms' => $kline['open_time_ms'],
                'open' => $kline['open'],
                'high' => $kline['high'],
                'low' => $kline['low'],
                'close' => $kline['close'],
                'volume' => $kline['volume'],
                'close_time_ms' => $kline['close_time_ms'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('candles')->upsert(
            $candles,
            ['symbol_code', 'timeframe_code', 'open_time_ms']
        );
    }

    /**
     * Sync quotes for multiple FX symbols using batch quotes provider.
     * Falls back to individual provider calls for non-FX symbols.
     */
    public function syncFxQuotes(array $symbolCodes): void
    {
        if (empty($symbolCodes)) {
            return;
        }

        // Separate FX symbols (6-letter uppercase) from others
        $fxSymbols = [];
        $otherSymbols = [];

        foreach ($symbolCodes as $symbolCode) {
            if (preg_match('/^[A-Z]{6}$/', $symbolCode)) {
                $fxSymbols[] = $symbolCode;
            } else {
                $otherSymbols[] = $symbolCode;
            }
        }

        // Sync FX symbols using batch provider
        if (!empty($fxSymbols)) {
            try {
                $fxQuotes = $this->fxQuotesProvider->batchQuotes($fxSymbols);

                foreach ($fxSymbols as $symbolCode) {
                    $price = $fxQuotes[$symbolCode] ?? null;

                    if ($price !== null && $price > 0) {
                        // Success: update with price
                        SymbolQuote::updateOrCreate(
                            ['symbol_code' => $symbolCode],
                            [
                                'price' => $price,
                                'source' => $this->fxQuotesProvider->source(),
                                'pulled_at' => now(),
                                'updated_at' => now(),
                            ]
                        );
                    }
                    // If price missing/null/<=0: DO NOTHING (no update at all)
                }
            } catch (\Exception $e) {
                // In the catch block for the whole batch: Just report($e) and DO NOT update any SymbolQuote rows
                report($e);
            }
        }

        // Sync non-FX symbols using individual provider calls
        foreach ($otherSymbols as $symbolCode) {
            $this->syncSymbolQuote($symbolCode);
        }
    }
}
