<?php

namespace App\Services\MarketData;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class TwelveDataApiKeyPool
{
    private const INDEX_CACHE_KEY = 'twelvedata:key_index';
    private const COOLDOWN_PREFIX = 'twelvedata:cooldown:';
    private const COOLDOWN_HOURS = 6;

    /**
     * @return array<int, string>
     */
    public function getKeys(): array
    {
        $raw = config('services.twelvedata.key');

        if (!is_string($raw) || trim($raw) === '') {
            $raw = env('TWELVEDATA_API_KEY', '');
        }

        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $parts = array_map('trim', explode(',', $raw));
        $parts = array_values(array_filter($parts, fn ($k) => is_string($k) && $k !== ''));

        return array_values(array_unique($parts));
    }

    public function pickKey(): ?string
    {
        $keys = $this->getKeys();

        if (empty($keys)) {
            return null;
        }

        $count = count($keys);

        Cache::add(self::INDEX_CACHE_KEY, 0);

        $start = (int) Cache::increment(self::INDEX_CACHE_KEY);

        for ($i = 0; $i < $count; $i++) {
            $idx = ($start + $i) % $count;
            $key = $keys[$idx];

            if (!$this->isCoolingDown($key)) {
                Log::debug('[TwelveData] pickKey key_id=' . substr(sha1($key), 0, 8));
                return $key;
            }
        }

        return null;
    }

    public function markRateLimited(string $key): void
    {
        Log::warning('[TwelveData] cooldown set key_id=' . substr(sha1($key), 0, 8) . ' ttl_hours=' . self::COOLDOWN_HOURS);
        Cache::put($this->cooldownCacheKey($key), true, now()->addHours(self::COOLDOWN_HOURS));
    }

    /**
     * Execute one-request callable with automatic key rotation on 429.
     *
     * The callable MUST perform exactly one HTTP request using the provided $apiKey.
     * If the request is rate-limited, it should either:
     * - throw RequestException with HTTP status 429 (e.g. Response->throw()), or
     * - throw TwelveDataRateLimitedException.
     *
     * @template T
     * @param callable(string):T $fn
     * @return T
     */
    public function withFailover(callable $fn): mixed
    {
        $keys = $this->getKeys();
        $attemptsLeft = count($keys);

        if ($attemptsLeft === 0) {
            throw new \RuntimeException('TwelveData API key is not configured.');
        }

        $last = null;

        while ($attemptsLeft > 0) {
            $apiKey = $this->pickKey();

            if (!is_string($apiKey) || $apiKey === '') {
                break;
            }

            Log::debug('[TwelveData] request attempt key_id=' . substr(sha1($apiKey), 0, 8));

            try {
                return $fn($apiKey);
            } catch (Throwable $e) {
                $last = $e;

                if ($this->isRateLimitedThrowable($e)) {
                    Log::warning('[TwelveData] rate_limited key_id=' . substr(sha1($apiKey), 0, 8) . ' -> failover');
                    $this->markRateLimited($apiKey);
                    $attemptsLeft--;
                    continue;
                }

                throw $e;
            }
        }

        Log::error('[TwelveData] all_keys_exhausted');
        throw new \RuntimeException('TwelveData rate limit: all keys exhausted', 0, $last);
    }

    private function isRateLimitedThrowable(Throwable $e): bool
    {
        if ($e instanceof TwelveDataRateLimitedException) {
            return true;
        }

        if ($e instanceof RequestException) {
            return $e->response?->status() === 429;
        }

        return false;
    }

    private function isCoolingDown(string $key): bool
    {
        return (bool) Cache::get($this->cooldownCacheKey($key), false);
    }

    private function cooldownCacheKey(string $key): string
    {
        return self::COOLDOWN_PREFIX . sha1($key);
    }
}

class TwelveDataRateLimitedException extends \RuntimeException
{
}
