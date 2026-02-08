<?php

namespace App\Contracts;

interface StrategySettingsRepository
{
    /**
     * Get strategy settings.
     *
     * @return array{
     *     timeframes: string[],
     *     weights: array<string, int>,
     *     total_threshold: int,
     *     flat: array{
     *         lookback_candles: int,
     *         range_pct_threshold: float
     *     },
     *     entry: array{
     *         use_current_candle: bool
     *     }
     * }
     */
    public function get(): array;
}
