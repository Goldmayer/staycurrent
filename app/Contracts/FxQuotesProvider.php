<?php

namespace App\Contracts;

use Illuminate\Http\Client\Response;
use Throwable;

interface FxQuotesProvider
{
    /**
     * Get the data provider source identifier.
     */
    public function source(): string;

    /**
     * Get batch quotes for multiple FX symbols.
     *
     * @param array $fxSymbolCodes Array of FX symbol codes (e.g., ['EURUSD', 'GBPUSD'])
     * @return array Map of symbolCode => price(float), only for provided symbols; empty array on failure
     */
    public function batchQuotes(array $fxSymbolCodes): array;

    /**
     * Determine if the given exception indicates a rate limit.
     *
     * @param Throwable $e The exception that occurred
     * @param Response|null $response The HTTP response, if available
     * @return bool True if the exception indicates a rate limit (429)
     */
    public function isRateLimited(Throwable $e, ?Response $response = null): bool;
}
