<?php

namespace App\Services\MarketData;

use App\Enums\TimeframeCode;
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

            if ($price === null || $price === '' || !is_numeric($price)) {
                throw new \RuntimeException("Binance lastPrice returned invalid value for {$symbolCode}");
            }

            SymbolQuote::updateOrCreate(
                ['symbol_code' => $symbolCode],
                [
                    'price' => $price,
                    'source' => 'binance',
                    'pulled_at' => now(),
                    'updated_at' => now(),
                ]
            );
        } catch (\Exception $e) {
            SymbolQuote::query()
                       ->where('symbol_code', $symbolCode)
                       ->update([
                           'source' => 'binance_error',
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

        foreach (TimeframeCode::cases() as $timeframe) {
            try {
                $klines = $this->client->klines(
                    $symbolCode,
                    $timeframe->value,
                    limit: $limit
                );

                $this->upsertCandles($symbolCode, $timeframe->value, $klines);
            } catch (\Exception $e) {
                report($e);
            }
        }
    }

    private function upsertCandles(string $symbolCode, string $timeframeCode, array $klines): void
    {
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
}
