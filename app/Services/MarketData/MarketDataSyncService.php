<?php

namespace App\Services\MarketData;

use App\Enums\TimeframeCode;
use App\Models\Candle;
use App\Models\Symbol;
use App\Models\SymbolQuote;
use Illuminate\Support\Facades\DB;

class MarketDataSyncService
{
    private readonly BinanceMarketDataClient $client;

    public function __construct()
    {
        $this->client = new BinanceMarketDataClient();
    }

    public function syncSymbol(string $symbolCode): void
    {
        $this->syncSymbolQuote($symbolCode);
        $this->syncSymbolCandles($symbolCode);
    }

    public function syncSymbolQuote(string $symbolCode): void
    {
        try {
            $price = $this->client->lastPrice($symbolCode);

            SymbolQuote::updateOrCreate(
                ['symbol_code' => $symbolCode],
                [
                    'price' => $price,
                    'source' => 'binance',
                    'pulled_at' => now(),
                ]
            );
        } catch (\Exception $e) {
            // Log error and continue
            report($e);
        }
    }

    public function syncSymbolCandles(string $symbolCode, int $limit = 200): void
    {
        $symbol = Symbol::where('code', $symbolCode)->first();

        if (!$symbol) {
            return;
        }

        foreach (TimeframeCode::cases() as $timeframe) {
            try {
                $klines = $this->client->klines(
                    $symbolCode,
                    $timeframe->value,
                    limit: $limit
                );

                $this->upsertCandles($symbolCode, $timeframe->value, $klines);
            } catch (\Exception $e) {
                // Log error and continue to next timeframe
                report($e);
            }
        }
    }

    private function upsertCandles(string $symbolCode, string $timeframeCode, array $klines): void
    {
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
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        DB::table('candles')->upsert(
            $candles,
            ['symbol_code', 'timeframe_code', 'open_time_ms']
        );
    }
}
