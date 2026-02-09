<?php

namespace App\Contracts;

interface MarketDataProvider
{
    /**
     * Get the data provider source identifier.
     */
    public function source(): string;

    /**
     * Get the last price for a symbol.
     */
    public function lastPrice(string $symbolCode): ?float;

    /**
     * Get OHLC candles for a symbol and timeframe.
     *
     * @return array<int, array{
     *     open_time_ms: int,
     *     open: float,
     *     high: float,
     *     low: float,
     *     close: float,
     *     volume: float|null,
     *     close_time_ms: int
     * }>
     */
    public function candles(string $symbolCode, string $timeframeCode, int $limit = 200): array;
}
