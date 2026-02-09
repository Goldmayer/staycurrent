<?php

namespace App\Services\MarketData;

use App\Contracts\FxQuotesProvider;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class TwelveDataFxQuotesProvider implements FxQuotesProvider
{
    private const BASE_URL = 'https://api.twelvedata.com';
    private const CACHE_KEY_PREFIX = 'fx_quotes:twelvedata:';
    private const CACHE_SECONDS = 20;

    public function source(): string
    {
        return 'twelvedata';
    }

    public function batchQuotes(array $fxSymbolCodes): array
    {
        $codes = array_values(array_unique(array_filter(
            $fxSymbolCodes,
            fn ($c) => is_string($c) && preg_match('/^[A-Z]{6}$/', $c)
        )));

        if (empty($codes)) {
            return [];
        }

        $cacheKey = self::CACHE_KEY_PREFIX . md5(implode(',', $codes));
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $this->filterToRequested($cached, $codes);
        }

        $symbols = implode(',', array_map([$this, 'toTwelveDataSymbol'], $codes));
        $pool = app(TwelveDataApiKeyPool::class);

        try {
            $data = $pool->withFailover(function (string $apiKey) use ($symbols) {
                $response = Http::retry(2, 200)
                                ->timeout(10)
                                ->acceptJson()
                                ->get(self::BASE_URL . '/price', [
                                    'symbol' => $symbols,
                                    'apikey' => $apiKey,
                                ]);

                $response->throw();

                return $response->json();
            });

            $mapped = $this->mapResponseToInternalCodes($data);

            Cache::put($cacheKey, $mapped, self::CACHE_SECONDS);

            return $this->filterToRequested($mapped, $codes);
        } catch (Throwable $e) {
            if ($this->isRateLimited($e, $e instanceof RequestException ? $e->response : null)) {
                if ($e instanceof \RuntimeException && str_contains($e->getMessage(), 'TwelveData rate limit')) {
                    Log::error('[TwelveData] ALL KEYS EXHAUSTED — throwing exception');
                    throw $e;
                }
                Log::warning('[TwelveData] quotes exhausted -> returning empty quotes');
                return [];
            }

            report($e);
            return [];
        }
    }

    public function isRateLimited(Throwable $e, ?Response $response = null): bool
    {
        $status = $response?->status();

        if ($e instanceof RequestException && $status === 429) {
            return true;
        }

        if ($e instanceof \RuntimeException && str_contains($e->getMessage(), 'TwelveData rate limit')) {
            return true;
        }

        return $status === 429;
    }

    private function toTwelveDataSymbol(string $internalCode): string
    {
        $base = substr($internalCode, 0, 3);
        $quote = substr($internalCode, 3, 3);

        return $base . '/' . $quote;
    }

    private function mapResponseToInternalCodes(mixed $data): array
    {
        if (!is_array($data)) {
            return [];
        }

        if (array_key_exists('code', $data) && array_key_exists('message', $data)) {
            return [];
        }

        $out = [];

        if (array_key_exists('price', $data) && (is_string($data['price']) || is_numeric($data['price']))) {
            if (isset($data['symbol']) && is_string($data['symbol'])) {
                $internal = str_replace('/', '', strtoupper($data['symbol']));
                $out[$internal] = (float) $data['price'];
            }

            return $out;
        }

        foreach ($data as $symbol => $payload) {
            if (!is_string($symbol) || !is_array($payload)) {
                continue;
            }

            $price = $payload['price'] ?? null;
            if (!is_string($price) && !is_numeric($price)) {
                continue;
            }

            $internal = str_replace('/', '', strtoupper($symbol));
            $out[$internal] = (float) $price;
        }

        return $out;
    }

    private function filterToRequested(array $all, array $requestedCodes): array
    {
        $out = [];
        foreach ($requestedCodes as $code) {
            if (array_key_exists($code, $all) && is_numeric($all[$code])) {
                $out[$code] = (float) $all[$code];
            }
        }

        return $out;
    }
}
