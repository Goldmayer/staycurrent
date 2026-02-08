<?php

namespace App\Services\Trading;

use App\Contracts\StrategySettingsRepository;

class ConfigStrategySettingsRepository implements StrategySettingsRepository
{
    public function get(): array
    {
        $config = config('trading.strategy', []);

        $timeframes = $config['timeframes'] ?? ['5m', '15m', '30m', '1h', '4h', '1d'];
        $weights = $config['weights'] ?? array_combine(
            $timeframes,
            range(1, count($timeframes))
        );

        return [
            'timeframes' => $timeframes,
            'weights' => $weights,
            'total_threshold' => $config['total_threshold'] ?? 8,
            'flat' => [
                'lookback_candles' => $config['flat']['lookback_candles'] ?? 12,
                'range_pct_threshold' => $config['flat']['range_pct_threshold'] ?? 0.002,
            ],
            'entry' => [
                'use_current_candle' => $config['entry']['use_current_candle'] ?? true,
            ],
            'risk' => [
                'stop_loss_points' => $config['risk']['stop_loss_points'] ?? 20,
                'take_profit_points' => $config['risk']['take_profit_points'] ?? 60,
                'max_hold_minutes' => $config['risk']['max_hold_minutes'] ?? 120,
                'trailing' => [
                    'enabled' => (bool)($config['risk']['trailing']['enabled'] ?? false),
                    'activation_points' => (float)($config['risk']['trailing']['activation_points'] ?? 30),
                    'distance_points' => (float)($config['risk']['trailing']['distance_points'] ?? 25),
                ],
            ],
        ];
    }
}
