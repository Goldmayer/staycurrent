<?php

namespace App\Services\MarketData;

use App\Contracts\FxQuotesProvider;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

class FmpFxQuotesProvider implements FxQuotesProvider
{
    public function source(): string
    {
        return 'fmp';
    }

    public function batchQuotes(array $fxSymbolCodes): array
    {
        // Filter to only 6-letter uppercase FX codes
        $validCodes = array_filter($fxSymbolCodes, fn($code) => preg_match('/^[A-Z]{6}$/', $code));

        if (empty($validCodes)) {
            return [];
        }

        $response = Http::retry(2, 200)
            ->get('https://financialmodelingprep.com/stable/batch-forex-quotes', [
                'apikey' => config('services.fmp.key'),
            ])
            ->throw();

        $data = $response->json();
        if (!is_array($data)) {
            return [];
        }

        $result = [];

        foreach ($data as $item) {
            if (isset($item['symbol']) && isset($item['price'])) {
                $symbolCode = $item['symbol'];
                // Only include symbols that were requested
                if (in_array($symbolCode, $validCodes, true)) {
                    $result[$symbolCode] = (float) $item['price'];
                }
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
}
