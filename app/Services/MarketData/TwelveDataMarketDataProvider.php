<?php

namespace App\Services\MarketData;

use App\Contracts\MarketDataProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

class TwelveDataMarketDataProvider implements MarketDataProvider
{
    protected string $apiKey;
    protected string $baseUrl = 'https://api.twelvedata.com';

    public function __construct()
    {
        $this->apiKey = config('services.twelvedata.key');
    }

    public function source(): string
    {
        return 'twelvedata';
    }

    public function lastPrice(string $symbolCode): ?float
    {
        // Strict API key guard
        if (empty($this->apiKey)) {
            return null;
        }

        $symbol = $this->mapSymbolCode($symbolCode);

        $data = $this->requestJson('/price', [
            'symbol' => $symbol,
            'apikey' => $this->apiKey,
        ]);

        if ($data === null) {
            return null;
        }

        if (isset($data['price'])) {
            return (float) $data['price'];
        }

        return null;
    }

    public function candles(string $symbolCode, string $timeframeCode, int $limit = 200): array
    {
        // Strict API key guard
        if (empty($this->apiKey)) {
            return [];
        }

        $symbol = $this->mapSymbolCode($symbolCode);

        // Implement proper interval mapping for TwelveData API
        $intervalMap = [
            '5m'  => '5min',
            '15m' => '15min',
            '30m' => '30min',
            '1h'  => '1h',
            '4h'  => '4h',
            '1d'  => '1day',
        ];

        $interval = $intervalMap[$timeframeCode] ?? $timeframeCode;

        $data = $this->requestJson('/time_series', [
            'symbol' => $symbol,
            'interval' => $interval,
            'outputsize' => $limit,
            'format' => 'JSON',
            'apikey' => $this->apiKey,
        ]);

        if ($data === null) {
            return [];
        }

        if (!isset($data['values']) || !is_array($data['values'])) {
            return [];
        }

        $timeframeMs = $this->getTimeframeDurationMs($timeframeCode);
        $candles = [];

        foreach ($data['values'] as $candleData) {
            // Parse candle timestamps in UTC deterministically
            $timestamp = CarbonImmutable::parse($candleData['datetime'], 'UTC')->getTimestamp() * 1000;

            $candles[] = [
                'open_time_ms' => $timestamp,
                'open' => (float) $candleData['open'],
                'high' => (float) $candleData['high'],
                'low' => (float) $candleData['low'],
                'close' => (float) $candleData['close'],
                'volume' => isset($candleData['volume']) ? (float) $candleData['volume'] : null,
                'close_time_ms' => $timestamp + $timeframeMs,
            ];
        }

        // Sort by timestamp ascending (oldest first)
        usort($candles, function ($a, $b) {
            return $a['open_time_ms'] <=> $b['open_time_ms'];
        });

        return $candles;
    }

    protected function mapSymbolCode(string $symbolCode): string
    {
        // Map EURUSD to EUR/USD format
        if (strlen($symbolCode) === 6) {
            return substr($symbolCode, 0, 3) . '/' . substr($symbolCode, 3, 3);
        }

        return $symbolCode;
    }

    protected function mapTimeframeCode(string $timeframeCode): ?string
    {
        $mappings = [
            '5m' => '5min',
            '15m' => '15min',
            '30m' => '30min',
            '1h' => '1h',
            '4h' => '4h',
            '1d' => '1day',
        ];

        return $mappings[$timeframeCode] ?? null;
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

        return $durations[$timeframeCode] ?? (60 * 60 * 1000); // Default to 1 hour
    }

    /**
     * Perform HTTP GET request with rate limit handling for Twelve Data API.
     *
     * @param string $path API endpoint path (e.g., '/price', '/time_series')
     * @param array $query Query parameters
     * @return array|null JSON response array on success, null on failure
     */
    private function requestJson(string $path, array $query): ?array
    {
        $response = Http::get($this->baseUrl . $path, $query);

        if ($response->failed()) {
            return null;
        }

        $data = $response->json();

        // Check for rate limit error (429)
        if (isset($data['status']) && $data['status'] === 'error' &&
            isset($data['code']) && $data['code'] === 429) {
            // Wait 60 seconds then retry once
            sleep(60);
            $response = Http::get($this->baseUrl . $path, $query);

            if ($response->failed()) {
                return null;
            }

            $data = $response->json();

            // If still rate limited after retry, return null
            if (isset($data['status']) && $data['status'] === 'error' &&
                isset($data['code']) && $data['code'] === 429) {
                return null;
            }
        }

        return $data;
    }
}
