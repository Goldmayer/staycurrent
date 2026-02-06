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

        // Check for no-trade zone based on small HA candle body
        $bodySize = abs($lastHa['ha_close'] - $lastHa['ha_open']);
        if ($bodySize < 0.0005) {
            return [
                'action' => 'hold',
                'reason' => 'no_trade_zone',
                'ha_dir' => null,
            ];
        }

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

    /**
     * Decide whether to close a trade based on HA reversal
     */
    public function decideClose(string $side, string $symbolCode, string $timeframeCode): array
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

        // Take the last two HA candles
        $prevHa = $haCandles[count($haCandles) - 2];
        $currHa = $haCandles[count($haCandles) - 1];

        // Determine direction for each
        $prevDir = $this->haDirection($prevHa['ha_open'], $prevHa['ha_close']);
        $currDir = $this->haDirection($currHa['ha_open'], $currHa['ha_close']);

        // If either direction is flat, hold
        if ($prevDir === 'flat' || $currDir === 'flat') {
            return [
                'action' => 'hold',
                'reason' => 'no_reversal',
                'ha_prev_dir' => $prevDir,
                'ha_curr_dir' => $currDir,
            ];
        }

        // Decision based on trade side
        if ($side === 'buy') {
            // For buy trades, close only on up->down reversal
            if ($prevDir === 'up' && $currDir === 'down') {
                return [
                    'action' => 'close',
                    'reason' => 'ha_reversal',
                    'ha_prev_dir' => $prevDir,
                    'ha_curr_dir' => $currDir,
                ];
            }
            return [
                'action' => 'hold',
                'reason' => 'no_reversal',
                'ha_prev_dir' => $prevDir,
                'ha_curr_dir' => $currDir,
            ];
        }

        if ($side === 'sell') {
            // For sell trades, close only on down->up reversal
            if ($prevDir === 'down' && $currDir === 'up') {
                return [
                    'action' => 'close',
                    'reason' => 'ha_reversal',
                    'ha_prev_dir' => $prevDir,
                    'ha_curr_dir' => $currDir,
                ];
            }
            return [
                'action' => 'hold',
                'reason' => 'no_reversal',
                'ha_prev_dir' => $prevDir,
                'ha_curr_dir' => $currDir,
            ];
        }

        // Fallback for unknown side
        return [
            'action' => 'hold',
            'reason' => 'no_reversal',
            'ha_prev_dir' => $prevDir,
            'ha_curr_dir' => $currDir,
        ];
    }

    private function haDirection(float $haOpen, float $haClose): string
    {
        if ($haClose > $haOpen) {
            return 'up';
        }
        if ($haClose < $haOpen) {
            return 'down';
        }
        return 'flat';
    }
}
