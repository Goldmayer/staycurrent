<?php

namespace App\Services\MarketData;

use App\Contracts\FxQuotesProvider;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

class FinnhubFxQuotesProvider implements FxQuotesProvider
{
    public function source(): string
    {
        return 'finnhub';
    }

    public function batchQuotes(array $fxSymbolCodes): array
    {
        // Filter to only 6-letter uppercase FX codes
        $validCodes = array_filter($fxSymbolCodes, fn($code) => preg_match('/^[A-Z]{6}$/', $code));

        if (empty($validCodes)) {
            return [];
        }

        $response = Http::retry(2, 200)
            ->get('https://finnhub.io/api/v1/forex/rates', [
                'base' => 'USD',
                'token' => config('services.finnhub.key'),
            ])
            ->throw();

        $data = $response->json();
        if (!isset($data['quote']) || !is_array($data['quote'])) {
            return [];
        }

        $rates = $data['quote'];
        $base = $data['base'] ?? 'USD';

        $result = [];

        foreach ($validCodes as $symbolCode) {
            $price = $this->calculatePrice($symbolCode, $base, $rates);
            if ($price !== null) {
                $result[$symbolCode] = $price;
            }
        }

        return $result;
    }

    public function isRateLimited(Throwable $e, ?Response $response = null): bool
    {
        if ($response && $response->status() === 429) {
            return true;
        }

        return false;
    }

    private function calculatePrice(string $symbolCode, string $base, array $rates): ?float
    {
        $baseCurrency = substr($symbolCode, 0, 3);
        $quoteCurrency = substr($symbolCode, 3, 3);

        // If base == API base currency: price = 1 / rates[baseCurrency]
        if ($base === $baseCurrency) {
            return isset($rates[$quoteCurrency]) ? 1 / $rates[$quoteCurrency] : null;
        }

        // If quote == API base currency: price = rates[baseCurrency]
        if ($base === $quoteCurrency) {
            return $rates[$baseCurrency] ?? null;
        }

        // Otherwise cross via API base: price = rates[baseCurrency] / rates[quoteCurrency]
        if (isset($rates[$baseCurrency]) && isset($rates[$quoteCurrency])) {
            return $rates[$baseCurrency] / $rates[$quoteCurrency];
        }

        return null;
    }
}
