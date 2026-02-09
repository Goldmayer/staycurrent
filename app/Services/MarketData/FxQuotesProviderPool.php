<?php

namespace App\Services\MarketData;

use App\Contracts\FxQuotesProvider;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Throwable;

class FxQuotesProviderPool implements FxQuotesProvider
{
    private const COOLDOWN_MINUTES = 15;

    public function __construct(
        private readonly array $providers
    ) {
    }

    public function source(): string
    {
        return 'pool';
    }

    public function batchQuotes(array $fxSymbolCodes): array
    {
        $validCodes = array_filter($fxSymbolCodes, fn ($code) => preg_match('/^[A-Z]{6}$/', $code));

        if (empty($validCodes)) {
            return [];
        }

        $provider = $this->providers[0];
        $source = $provider->source();
        $cooldownKey = "fx_quotes_cooldown:{$source}";

        if (Cache::get($cooldownKey)) {
            return [];
        }

        try {
            return $provider->batchQuotes($validCodes);
        } catch (Throwable $e) {
            if ($e instanceof RequestException && $e->response?->status() === 429) {
                Cache::put($cooldownKey, true, now()->addMinutes(self::COOLDOWN_MINUTES));
                return [];
            }

            report($e);
            return [];
        }
    }

    public function isRateLimited(Throwable $e, ?Response $response = null): bool
    {
        return false;
    }
}
