<?php

namespace App\Services\MarketData;

use App\Contracts\MarketDataProvider;
use App\Models\User;
use App\Services\Notifications\SignalNotificationService;
use Carbon\CarbonImmutable;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class TwelveDataMarketDataProvider implements MarketDataProvider
{
    protected string $baseUrl = 'https://api.twelvedata.com';

    public function source(): string
    {
        return 'twelvedata';
    }

    public function lastPrice(string $symbolCode): ?float
    {
        $symbol = $this->mapSymbolCode($symbolCode);

        $data = $this->requestJson('/price', [
            'symbol' => $symbol,
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
        $symbol = $this->mapSymbolCode($symbolCode);

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

        usort($candles, function ($a, $b) {
            return $a['open_time_ms'] <=> $b['open_time_ms'];
        });

        return $candles;
    }

    protected function mapSymbolCode(string $symbolCode): string
    {
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

        return $durations[$timeframeCode] ?? (60 * 60 * 1000);
    }

    private function requestJson(string $path, array $query): ?array
    {
        $pool = app(TwelveDataApiKeyPool::class);

        try {
            $data = $pool->withFailover(function (string $apiKey) use ($path, $query) {
                $q = $query;
                $q['apikey'] = $apiKey;

                $response = Http::timeout(15)
                                ->acceptJson()
                                ->get($this->baseUrl . $path, $q);

                $json = $response->json();

                if (is_array($json)
                    && ($json['status'] ?? null) === 'error'
                    && ((int) ($json['code'] ?? 0)) === 429
                ) {
                    throw new TwelveDataRateLimitedException('TwelveData rate limited');
                }

                $response->throw();

                return $json;
            });

            return is_array($data) ? $data : null;
        } catch (\RuntimeException $e) {
            // Only "all keys exhausted" should be handled specially; everything else must be visible.
            if (str_contains($e->getMessage(), 'TwelveData rate limit')) {
                Log::error('[TwelveData] candles exhausted -> throwing');
                throw $e;
            }

            throw $e;
        } catch (\Throwable $e) {
            $this->notifyProviderError($e);
            return null;
        }
    }

    private function notifyProviderError(\Throwable $e): void
    {
        $notificationService = app(SignalNotificationService::class);

        $notificationService->notify([
            'type' => 'provider_error',
            'title' => 'Provider error',
            'message' => 'TwelveData provider request failed',
            'level' => 'warning',
            'symbol' => null,
            'timeframe' => null,
            'reason' => $e->getMessage(),
            'happened_at' => now()->toISOString(),
        ]);

        // Send Filament database notification
        $user = User::query()->orderBy('id')->first();
        if ($user) {
            Notification::make()
                ->title("DATA PROVIDER ERROR")
                ->body($e->getMessage())
                ->danger()
                ->sendToDatabase($user);
        }
    }
}
