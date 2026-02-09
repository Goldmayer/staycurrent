<?php

namespace App\Services\MarketData;

use App\Contracts\FxQuotesProvider;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

class TwelveDataFxQuotesProvider implements FxQuotesProvider
{
    private array $cache = [];

    public function source(): string
    {
        return 'twelvedata';
    }

    public function batchQuotes(array $fxSymbolCodes): array
    {
        // Filter to only 6-letter uppercase FX codes
        $validCodes = array_filter($fxSymbolCodes, fn($code) => preg_match('/^[A-Z]{6}$/', $code));

        if (empty($validCodes)) {
            return [];
        }

        // Check cache first for all requested symbols
        $missingCodes = [];
        $result = [];

        foreach ($validCodes as $symbolCode) {
            if (isset($this->cache[$symbolCode])) {
                $result[$symbolCode] = $this->cache[$symbolCode];
            } else {
                $missingCodes[] = $symbolCode;
            }
        }

        // If all symbols are cached, return early
        if (empty($missingCodes)) {
            return $result;
        }

        // Convert internal codes to TwelveData format (EURUSD -> EUR/USD)
        $symbols = array_map(fn($code) => substr($code, 0, 3) . '/' . substr($code, 3, 3), $missingCodes);

        try {
            $response = Http::retry(2, 200)
                ->get('https://api.twelvedata.com/price', [
                    'symbol' => implode(',', $symbols),
                    'apikey' => config('services.twelvedata.key'),
                ])
                ->throw();

            $data = $response->json();

            // Handle single symbol response
            if (isset($data['price'])) {
                $symbol = $symbols[0];
                $price = (float)$data['price'];
                $internalCode = str_replace('/', '', $symbol);

                if ($price > 0) {
                    $this->cache[$internalCode] = $price;
                    $result[$internalCode] = $price;
                }
            }
            // Handle multiple symbols response
            elseif (is_array($data)) {
                foreach ($symbols as $symbol) {
                    $internalCode = str_replace('/', '', $symbol);

                    if (isset($data[$symbol]['price'])) {
                        $price = (float)$data[$symbol]['price'];
                        if ($price > 0) {
                            $this->cache[$internalCode] = $price;
                            $result[$internalCode] = $price;
                        }
                    }
                }
            }

            return $result;
        } catch (Throwable $e) {
            if ($e instanceof \Illuminate\Http\Client\RequestException && $e->response?->status() === 429) {
                // Rate limited - return empty array, cache will be cleared on next run
                return [];
            }

            report($e);
            return [];
        }
    }

    public function isRateLimited(Throwable $e, ?Response $response = null): bool
    {
        if ($response && $response->status() === 429) {
            return true;
        }

        return false;
    }
}
