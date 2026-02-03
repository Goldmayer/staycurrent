<?php

namespace App\Services\MarketData;

use Illuminate\Support\Facades\Http;

class BinanceMarketDataClient
{
    private const BASE_URL = 'https://api.binance.com';
    private const TIMEOUT = 5;
    private const RETRY_COUNT = 2;

    public function lastPrice(string $symbolCode): ?float
    {
        try {
            $response = Http::baseUrl(self::BASE_URL)
                ->acceptJson()
                ->timeout(self::TIMEOUT)
                ->retry(self::RETRY_COUNT, 100)
                ->get('/api/v3/ticker/price', [
                    'symbol' => $symbolCode
                ]);

            if ($response->successful() && $response->json('price') !== null) {
                return (float) $response->json('price');
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return null;
    }

    public function klines(string $symbolCode, string $interval, int $limit = 100, ?int $endTimeMs = null): array
    {
        try {
            $params = [
                'symbol' => $symbolCode,
                'interval' => $interval,
                'limit' => $limit
            ];

            if ($endTimeMs !== null) {
                $params['endTime'] = $endTimeMs;
            }

            $response = Http::baseUrl(self::BASE_URL)
                ->acceptJson()
                ->timeout(self::TIMEOUT)
                ->retry(self::RETRY_COUNT, 100)
                ->get('/api/v3/klines', $params);

            if ($response->successful() && is_array($response->json())) {
                return array_map(function ($kline) {
                    return [
                        'open_time_ms' => (int) $kline[0],
                        'open' => (float) $kline[1],
                        'high' => (float) $kline[2],
                        'low' => (float) $kline[3],
                        'close' => (float) $kline[4],
                        'volume' => (float) $kline[5],
                        'close_time_ms' => (int) $kline[6]
                    ];
                }, $response->json());
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return [];
    }
}
