<?php

namespace App\Services\Trading;

use App\Contracts\StrategySettingsRepository;
use App\Enums\TimeframeCode;
use App\Enums\TradeStatus;
use App\Models\Candle;
use App\Models\Symbol;
use App\Models\SymbolQuote;
use App\Models\Trade;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class TradeCloseService
{
    private const QUOTE_FRESH_MINUTES = 10;

    public function __construct(
        private readonly StrategySettingsRepository $settings
    ) {
    }

    public function process(?int $limit = null): array
    {
        $cfg = $this->settings->get();
        $trailingCfg = $cfg['risk']['trailing'] ?? [];
        $trailingEnabled = (bool) ($trailingCfg['enabled'] ?? false);
        $activationPoints = (float) ($trailingCfg['activation_points'] ?? 30);
        $distancePoints = (float) ($trailingCfg['distance_points'] ?? 25);

        $query = Trade::query()
                      ->where('status', TradeStatus::OPEN->value)
                      ->orderBy('id');

        if ($limit) {
            $query->limit($limit);
        }

        $trades = $query->get();

        $processed = 0;
        $closed = 0;
        $held = 0;

        $skippedMissingQuote = 0;
        $skippedStaleQuote = 0;
        $skippedMissingSymbol = 0;

        $exitStopMoved = 0;
        $exitStopHit = 0;

        $symbolCache = [];

        foreach ($trades as $tradeRow) {
            $processed++;

            DB::transaction(function () use (
                $tradeRow,
                &$closed,
                &$held,
                &$skippedMissingQuote,
                &$skippedStaleQuote,
                &$skippedMissingSymbol,
                &$symbolCache,
                &$exitStopMoved,
                &$exitStopHit,
                $trailingEnabled,
                $activationPoints,
                $distancePoints
            ) {
                /** @var Trade|null $trade */
                $trade = Trade::query()
                              ->whereKey($tradeRow->id)
                              ->where('status', TradeStatus::OPEN->value)
                              ->lockForUpdate()
                              ->first();

                if (!$trade) {
                    return;
                }

                $quote = SymbolQuote::query()
                                    ->where('symbol_code', $trade->symbol_code)
                                    ->first();

                if (!$quote || $quote->price === null) {
                    $skippedMissingQuote++;
                    return;
                }

                $mostRecentTimestamp = $this->mostRecentQuoteTimestamp($quote);
                if (!$mostRecentTimestamp || $mostRecentTimestamp->lt(now()->subMinutes(self::QUOTE_FRESH_MINUTES))) {
                    $skippedStaleQuote++;
                    return;
                }

                $priceNow = (float) $quote->price;

                $symbol = $symbolCache[$trade->symbol_code] ?? null;
                if ($symbol === null && !array_key_exists($trade->symbol_code, $symbolCache)) {
                    $symbol = Symbol::query()
                                    ->where('code', $trade->symbol_code)
                                    ->first();

                    $symbolCache[$trade->symbol_code] = $symbol;
                }

                if (!$symbol) {
                    $skippedMissingSymbol++;
                    return;
                }

                $pointSize = (float) $symbol->point_size;
                if ($pointSize <= 0) {
                    $skippedMissingSymbol++;
                    return;
                }

                $unrealizedPoints = $this->pointsFromPrices(
                    entryPrice: (float) $trade->entry_price,
                    exitPrice: $priceNow,
                    pointSize: $pointSize,
                    side: (string) $trade->side
                );

                $trade->unrealized_points = $unrealizedPoints;
                $trade->save();

                $meta = is_array($trade->meta) ? $trade->meta : [];

                // ------------------------------------------------------------
                // 0) TRAILING STOP (tighten stop in profit)
                // ------------------------------------------------------------
                if ($trailingEnabled && $unrealizedPoints >= $activationPoints) {
                    $side = (string) $trade->side;

                    $existingExitStop = $meta['exit_stop'] ?? null;
                    $existingStopPrice = (is_array($existingExitStop) && isset($existingExitStop['stop_price']))
                        ? (float) $existingExitStop['stop_price']
                        : null;

                    // Base SL price if no stop exists yet
                    if ($existingStopPrice === null) {
                        $entry = (float) $trade->entry_price;
                        $sl = $this->slPoints($trade);
                        $existingStopPrice = $side === 'buy'
                            ? ($entry - ($sl * $pointSize))
                            : ($entry + ($sl * $pointSize));
                    }

                    $candidate = $side === 'buy'
                        ? ($priceNow - ($distancePoints * $pointSize))
                        : ($priceNow + ($distancePoints * $pointSize));

                    $shouldUpdate = $side === 'buy'
                        ? ($candidate > $existingStopPrice)
                        : ($candidate < $existingStopPrice);

                    if ($shouldUpdate) {
                        $exitStop = is_array($existingExitStop) ? $existingExitStop : [];

                        // do NOT wipe existing fields; just tighten stop
                        $exitStop['armed_at'] = now()->toDateTimeString();
                        $exitStop['reason'] = 'profit_trailing';
                        $exitStop['stop_price'] = $candidate;

                        $exitStop['trail_activation_points'] = $activationPoints;
                        $exitStop['trail_distance_points'] = $distancePoints;
                        $exitStop['trail_price_now_at_update'] = $priceNow;
                        $exitStop['trail_previous_stop_price'] = $existingStopPrice;
                        $exitStop['trail_candidate_stop_price'] = $candidate;

                        $meta['exit_stop'] = $exitStop;

                        $trade->meta = $meta;
                        $trade->save();

                        $exitStopMoved++;
                    }
                }

                // ------------------------------------------------------------
                // 1) EXIT STOP (close on hit) - execute at level
                // ------------------------------------------------------------
                $exitStop = $meta['exit_stop'] ?? null;
                if (is_array($exitStop) && isset($exitStop['stop_price'])) {
                    $stopPrice = (float) $exitStop['stop_price'];

                    $hit = ((string) $trade->side === 'buy')
                        ? ($priceNow <= $stopPrice)
                        : ($priceNow >= $stopPrice);

                    if ($hit) {
                        $exitPrice = $stopPrice;

                        $realizedPoints = $this->pointsFromPrices(
                            entryPrice: (float) $trade->entry_price,
                            exitPrice: $exitPrice,
                            pointSize: $pointSize,
                            side: (string) $trade->side
                        );

                        $meta['close'] = [
                            'source' => 'trade:close',
                            'reason' => 'exit_stop_hit',
                            'quote_pulled_at' => $mostRecentTimestamp->toDateTimeString(),
                            'trade_tf' => (string) $trade->timeframe_code,
                            'unrealized_points_at_close' => $unrealizedPoints,
                            'exit_stop' => $exitStop,
                            'exit_price_mode' => 'level',
                            'exit_price_level' => $stopPrice,
                            'price_now' => $priceNow,
                        ];

                        $trade->status = TradeStatus::CLOSED;
                        $trade->closed_at = now();
                        $trade->exit_price = $exitPrice;
                        $trade->realized_points = $realizedPoints;
                        $trade->unrealized_points = 0;
                        $trade->meta = $meta;
                        $trade->save();

                        $exitStopHit++;
                        $closed++;
                        return;
                    }
                }

                // ------------------------------------------------------------
                // 2) HARD EXITS (SL/TP/Time) with execution at LEVEL (not priceNow)
                // ------------------------------------------------------------
                $hard = $this->detectHardExit($trade, $priceNow, $pointSize);

                if ($hard !== null) {
                    $exitPrice = (float) $hard['exit_price'];

                    $realizedPoints = $this->pointsFromPrices(
                        entryPrice: (float) $trade->entry_price,
                        exitPrice: $exitPrice,
                        pointSize: $pointSize,
                        side: (string) $trade->side
                    );

                    $meta['close'] = [
                        'source' => 'trade:close',
                        'reason' => (string) $hard['reason'],
                        'quote_pulled_at' => $mostRecentTimestamp->toDateTimeString(),
                        'trade_tf' => (string) $trade->timeframe_code,
                        'unrealized_points_at_close' => $unrealizedPoints,
                        'hold_minutes' => $trade->opened_at ? now()->diffInMinutes($trade->opened_at) : null,
                        'risk' => [
                            'stop_loss_points' => $this->slPoints($trade),
                            'take_profit_points' => $this->tpPoints($trade),
                            'max_hold_minutes' => $this->maxHoldMinutes($trade),
                        ],
                        'exit_stop' => $meta['exit_stop'] ?? null,
                        'exit_price_mode' => (string) ($hard['exit_price_mode'] ?? 'level'),
                        'exit_price_level' => (float) ($hard['exit_price_level'] ?? $exitPrice),
                        'price_now' => $priceNow,
                    ];

                    $trade->status = TradeStatus::CLOSED;
                    $trade->closed_at = now();
                    $trade->exit_price = $exitPrice;
                    $trade->realized_points = $realizedPoints;
                    $trade->unrealized_points = 0;
                    $trade->meta = $meta;
                    $trade->save();

                    $closed++;
                    return;
                }

                // ------------------------------------------------------------
                // 3) STRATEGY EXIT LOGIC: HA reversal -> arm/move exit_stop
                // ------------------------------------------------------------
                $tradeTf = (string) $trade->timeframe_code;
                $exitTf = $this->lowerTimeframe($tradeTf);

                if ($exitTf === null) {
                    $held++;
                    return;
                }

                $exitDir = $this->haDirFromCurrentCandle($trade->symbol_code, $exitTf);
                if ($exitDir === null) {
                    $held++;
                    return;
                }

                $wantedExitDir = ((string) $trade->side === 'buy') ? 'up' : 'down';
                $isAgainst = ($exitDir !== 'flat') && ($exitDir !== $wantedExitDir);

                if (!$isAgainst) {
                    $held++;
                    return;
                }

                $prevTradeCandle = Candle::query()
                                         ->where('symbol_code', $trade->symbol_code)
                                         ->where('timeframe_code', $tradeTf)
                                         ->orderByDesc('open_time_ms')
                                         ->skip(1)
                                         ->first();

                if (!$prevTradeCandle) {
                    $held++;
                    return;
                }

                $minPoints = $this->slPoints($trade);
                $minDist = $minPoints * $pointSize;

                $side = (string) $trade->side;
                $currentStop = null;
                if (isset($meta['exit_stop']) && is_array($meta['exit_stop']) && isset($meta['exit_stop']['stop_price'])) {
                    $currentStop = (float) $meta['exit_stop']['stop_price'];
                }

                if ($side === 'buy') {
                    $candleLevel = (float) $prevTradeCandle->low;

                    $maxAllowed = $priceNow - $minDist;
                    $candidate = min($candleLevel, $maxAllowed);

                    $shouldMove = $currentStop === null ? true : ($candidate > $currentStop);

                    if ($shouldMove) {
                        $meta['exit_stop'] = [
                            'armed_at' => now()->toDateTimeString(),
                            'reason' => 'exit_signal_ha_exit_tf_turned_down',
                            'stop_price' => $candidate,
                            'trade_tf' => $tradeTf,
                            'exit_tf' => $exitTf,
                            'min_distance_points' => $minPoints,
                            'min_distance_price' => $minDist,
                            'price_now_at_arm' => $priceNow,
                            'candle_level' => $candleLevel,
                            'clamped_by_min_distance' => ($candidate !== $candleLevel),
                            'previous_stop_price' => $currentStop,
                        ];

                        $trade->meta = $meta;
                        $trade->save();

                        $exitStopMoved++;
                    }

                    $held++;
                    return;
                }

                $candleLevel = (float) $prevTradeCandle->high;

                $minAllowed = $priceNow + $minDist;
                $candidate = max($candleLevel, $minAllowed);

                $shouldMove = $currentStop === null ? true : ($candidate < $currentStop);

                if ($shouldMove) {
                    $meta['exit_stop'] = [
                        'armed_at' => now()->toDateTimeString(),
                        'reason' => 'exit_signal_ha_exit_tf_turned_up',
                        'stop_price' => $candidate,
                        'trade_tf' => $tradeTf,
                        'exit_tf' => $exitTf,
                        'min_distance_points' => $minPoints,
                        'min_distance_price' => $minDist,
                        'price_now_at_arm' => $priceNow,
                        'candle_level' => $candleLevel,
                        'clamped_by_min_distance' => ($candidate !== $candleLevel),
                        'previous_stop_price' => $currentStop,
                    ];

                    $trade->meta = $meta;
                    $trade->save();

                    $exitStopMoved++;
                }

                $held++;
            });
        }

        return [
            'trades_processed' => $processed,
            'trades_closed' => $closed,
            'trades_held' => $held,
            'skipped_missing_quote' => $skippedMissingQuote,
            'skipped_stale_quote' => $skippedStaleQuote,
            'skipped_missing_symbol' => $skippedMissingSymbol,

            'skipped_not_enough_candles' => 0,
            'skipped_no_reversal' => 0,

            'exit_stop_armed' => $exitStopMoved,
            'exit_stop_hit' => $exitStopHit,
        ];
    }

    private function mostRecentQuoteTimestamp(SymbolQuote $quote): ?Carbon
    {
        $pulledAt = $quote->pulled_at ? Carbon::parse($quote->pulled_at) : null;
        $updatedAt = $quote->updated_at ? Carbon::parse($quote->updated_at) : null;

        if ($pulledAt && $updatedAt) {
            return $pulledAt->max($updatedAt);
        }

        return $pulledAt ?: $updatedAt;
    }

    /**
     * @return array{reason:string, exit_price:float, exit_price_mode:string, exit_price_level:float}|null
     */
    private function detectHardExit(Trade $trade, float $priceNow, float $pointSize): ?array
    {
        $sl = $this->slPoints($trade);
        $tp = $this->tpPoints($trade);
        $maxHold = $this->maxHoldMinutes($trade);

        $side = (string) $trade->side;
        $entry = (float) $trade->entry_price;

        if ($trade->opened_at && now()->diffInMinutes($trade->opened_at) >= $maxHold) {
            return [
                'reason' => 'time_stop',
                'exit_price' => $priceNow,
                'exit_price_mode' => 'market',
                'exit_price_level' => $priceNow,
            ];
        }

        if ($sl > 0) {
            $slPrice = $side === 'buy'
                ? ($entry - ($sl * $pointSize))
                : ($entry + ($sl * $pointSize));

            $hit = $side === 'buy'
                ? ($priceNow <= $slPrice)
                : ($priceNow >= $slPrice);

            if ($hit) {
                return [
                    'reason' => 'stop_loss_hit',
                    'exit_price' => $slPrice,
                    'exit_price_mode' => 'level',
                    'exit_price_level' => $slPrice,
                ];
            }
        }

        if ($tp > 0) {
            $tpPrice = $side === 'buy'
                ? ($entry + ($tp * $pointSize))
                : ($entry - ($tp * $pointSize));

            $hit = $side === 'buy'
                ? ($priceNow >= $tpPrice)
                : ($priceNow <= $tpPrice);

            if ($hit) {
                return [
                    'reason' => 'take_profit_hit',
                    'exit_price' => $tpPrice,
                    'exit_price_mode' => 'level',
                    'exit_price_level' => $tpPrice,
                ];
            }
        }

        return null;
    }

    private function slPoints(Trade $trade): float
    {
        $v = (float) ($trade->stop_loss_points ?? 0);
        return $v > 0 ? $v : 20.0;
    }

    private function tpPoints(Trade $trade): float
    {
        $v = (float) ($trade->take_profit_points ?? 0);
        return $v > 0 ? $v : 60.0;
    }

    private function maxHoldMinutes(Trade $trade): int
    {
        $v = (int) ($trade->max_hold_minutes ?? 0);
        return $v > 0 ? $v : 120;
    }

    private function pointsFromPrices(float $entryPrice, float $exitPrice, float $pointSize, string $side): float
    {
        $raw = ($side === 'buy')
            ? (($exitPrice - $entryPrice) / $pointSize)
            : (($entryPrice - $exitPrice) / $pointSize);

        return round($raw, 2);
    }

    private function lowerTimeframe(string $tf): ?string
    {
        return match ($tf) {
            TimeframeCode::D1->value => TimeframeCode::H4->value,
            TimeframeCode::H4->value => TimeframeCode::H1->value,
            TimeframeCode::H1->value => TimeframeCode::M30->value,
            TimeframeCode::M30->value => TimeframeCode::M15->value,
            TimeframeCode::M15->value => TimeframeCode::M5->value,
            default => null,
        };
    }

    private function haDirFromCurrentCandle(string $symbolCode, string $tf): ?string
    {
        $candle = Candle::query()
                        ->where('symbol_code', $symbolCode)
                        ->where('timeframe_code', $tf)
                        ->orderByDesc('open_time_ms')
                        ->first();

        if (!$candle) {
            return null;
        }

        $haClose = ((float) $candle->open + (float) $candle->high + (float) $candle->low + (float) $candle->close) / 4.0;
        $haOpen = ((float) $candle->open + (float) $candle->close) / 2.0;

        if ($haClose > $haOpen) {
            return 'up';
        }
        if ($haClose < $haOpen) {
            return 'down';
        }

        return 'flat';
    }
}
