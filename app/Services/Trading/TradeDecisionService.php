<?php

namespace App\Services\Trading;

use App\Models\Candle;

class TradeDecisionService
{
    /**
     * Decide whether to open a trade and which side based on HA trend
     */
    public function decideOpen(string $symbolCode, string $timeframeCode): array
    {
        $candles = Candle::where('symbol_code', $symbolCode)
                         ->where('timeframe_code', $timeframeCode)
                         ->orderByDesc('open_time_ms')
                         ->limit(3)
                         ->get();

        if ($candles->count() < 3) {
            return [
                'action' => 'hold',
                'reason' => 'not_enough_candles',
            ];
        }

        // Reverse to chronological order and reset keys
        $candles = $candles->reverse()->values();

        $haCandles = [];

        foreach ($candles as $index => $candle) {
            $open = (float) $candle->open;
            $high = (float) $candle->high;
            $low = (float) $candle->low;
            $close = (float) $candle->close;

            $haClose = ($open + $high + $low + $close) / 4.0;

            if ($index === 0) {
                $haOpen = ($open + $close) / 2.0;
            } else {
                $prev = $haCandles[$index - 1];
                $haOpen = ((float) $prev['ha_open'] + (float) $prev['ha_close']) / 2.0;
            }

            $haCandles[] = [
                'ha_open' => $haOpen,
                'ha_close' => $haClose,
            ];
        }

        $lastHa = $haCandles[count($haCandles) - 1];

        if ($lastHa['ha_close'] > $lastHa['ha_open']) {
            return [
                'action' => 'open',
                'side' => 'buy',
                'reason' => 'ha_trend_up',
                'ha_dir' => 'up',
            ];
        }

        if ($lastHa['ha_close'] < $lastHa['ha_open']) {
            return [
                'action' => 'open',
                'side' => 'sell',
                'reason' => 'ha_trend_down',
                'ha_dir' => 'down',
            ];
        }

        return [
            'action' => 'hold',
            'reason' => 'ha_flat',
        ];
    }
}
