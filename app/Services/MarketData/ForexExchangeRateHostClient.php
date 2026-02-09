<?php

namespace App\Services\MarketData;

use Illuminate\Support\Facades\Http;

class ForexExchangeRateHostClient
{
    private const BASE_URL = 'https://api.exchangerate.host';
    private const TIMEOUT = 5;
    private const RETRY_COUNT = 2;

    public function lastPrice(string $symbolCode): ?float
    {
        try {
            // Extract base and quote currencies from symbol code (e.g., EURUSD -> EUR, USD)
            $baseCurrency = substr($symbolCode, 0, 3);
            $quoteCurrency = substr($symbolCode, 3, 3);

            $response = Http::baseUrl(self::BASE_URL)
                ->acceptJson()
                ->timeout(self::TIMEOUT)
                ->retry(self::RETRY_COUNT, 100)
                ->get('/latest', [
                    'base' => $baseCurrency,
                    'symbols' => $quoteCurrency
                ]);

            if ($response->successful() && isset($response->json()['rates'][$quoteCurrency])) {
                return (float) $response->json()['rates'][$quoteCurrency];
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return null;
    }

    public function klines(string $symbolCode, string $interval, int $limit = 100, ?int $endTimeMs = null): array
    {
        // exchangerate.host doesn't provide historical candle data
        // Return empty array for Forex pairs
        return [];
    }
}
