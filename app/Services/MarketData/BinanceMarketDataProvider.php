<?php

namespace App\Services\MarketData;

use App\Contracts\MarketDataProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BinanceMarketDataProvider implements MarketDataProvider
{
    protected string $baseUrl = 'https://api.binance.com';

    public function source(): string
    {
        return 'binance';
    }

    public function lastPrice(string $symbolCode): ?float
    {
        try {
            $response = Http::timeout(10)
                ->acceptJson()
                ->get($this->baseUrl . '/api/v3/ticker/price', [
                    'symbol' => $symbolCode
                ]);

            $response->throw();

            $data = $response->json();

            if (isset($data['price'])) {
                return (float) $data['price'];
            }

            return null;
        } catch (\Throwable $e) {
            Log::error('[Binance] lastPrice failed for ' . $symbolCode, [
                'exception' => $e->getMessage()
            ]);
            return null;
        }
    }

    public function candles(string $symbolCode, string $timeframeCode, int $limit = 200): array
    {
        $intervalMap = [
            '5m'  => '5m',
            '15m' => '15m',
            '30m' => '30m',
            '1h'  => '1h',
            '4h'  => '4h',
            '1d'  => '1d',
        ];

        $interval = $intervalMap[$timeframeCode] ?? $timeframeCode;

        try {
            $response = Http::timeout(15)
                ->acceptJson()
                ->get($this->baseUrl . '/api/v3/klines', [
                    'symbol' => $symbolCode,
                    'interval' => $interval,
                    'limit' => $limit
                ]);

            $response->throw();

            $data = $response->json();

            if (!is_array($data)) {
                return [];
            }

            $timeframeMs = $this->getTimeframeDurationMs($timeframeCode);
            $candles = [];

            foreach ($data as $kline) {
                if (!is_array($kline) || count($kline) < 6) {
                    continue;
                }

                $candles[] = [
                    'open_time_ms' => (int) $kline[0],
                    'open' => (float) $kline[1],
                    'high' => (float) $kline[2],
                    'low' => (float) $kline[3],
                    'close' => (float) $kline[4],
                    'volume' => isset($kline[5]) ? (float) $kline[5] : null,
                    'close_time_ms' => (int) $kline[6],
                ];
            }

            return $candles;
        } catch (\Throwable $e) {
            Log::error('[Binance] candles failed for ' . $symbolCode, [
                'exception' => $e->getMessage()
            ]);
            return [];
        }
    }

    protected function getTimeframeDurationMs(string $timeframeCode): int
    {
        $durations = [
            '5m' => 5 * 60 * 1000,
            '15m' => 15 * 60 * 1000,
            '30m' => 30 * 60 * 1000,
            '1h' => 60 * 60 * 1000,
            '4h' => 4 * 60 * 60 * 1000,
            '1d' => 24 * 60 * 60 * 1000,
        ];

        return $durations[$timeframeCode] ?? (60 * 60 * 1000);
    }
}
