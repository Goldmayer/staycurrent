<?php

namespace App\Services\Trading;

use App\Contracts\StrategySettingsRepository;
use App\Enums\TradeStatus;
use App\Models\Symbol;
use App\Models\SymbolQuote;
use App\Models\Trade;
use App\Models\User;
use App\Services\Notifications\SignalNotificationService;
use Filament\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class TradeCloseService
{
    private const QUOTE_FRESH_MINUTES = 10;

    public function __construct(
        private readonly StrategySettingsRepository $settings,
        private readonly PriceWindowService $priceWindows,
    ) {}

    public function process(?int $limit = null): array
    {
        $cfg = $this->settings->get();

        $trailingCfg = $cfg['risk']['trailing'] ?? [];
        $trailingEnabled = (bool) ($trailingCfg['enabled'] ?? false);

        $activationPercent = (float) ($trailingCfg['activation_percent'] ?? 0.0);
        $distancePercent = (float) ($trailingCfg['distance_percent'] ?? 0.0);

        $activationPointsFallback = (float) ($trailingCfg['activation_points'] ?? 30);
        $distancePointsFallback = (float) ($trailingCfg['distance_points'] ?? 25);

        // price-only exit config
        $exitCfg = (array) config('trading.strategy.exit', []);
        $exitFlatThresholdPct = (float) (config('trading.strategy.price_windows.dir_flat_threshold_pct', 0.0001));

        $exitReversalMinStrengthPct = (float) ($exitCfg['reversal_min_strength_pct'] ?? 0.00015);
        $exitArmingMode = (string) ($exitCfg['exit_mode'] ?? 'market'); // market only for now

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
                $activationPercent,
                $distancePercent,
                $activationPointsFallback,
                $distancePointsFallback,
                $exitFlatThresholdPct,
                $exitReversalMinStrengthPct,
                $exitArmingMode,
                $cfg
            ) {
                /** @var Trade|null $trade */
                $trade = Trade::query()
                    ->whereKey($tradeRow->id)
                    ->where('status', TradeStatus::OPEN->value)
                    ->lockForUpdate()
                    ->first();

                if (! $trade) {
                    return;
                }

                $quote = SymbolQuote::query()
                    ->where('symbol_code', $trade->symbol_code)
                    ->first();

                if (! $quote || $quote->price === null) {
                    $skippedMissingQuote++;

                    return;
                }

                $mostRecentTimestamp = $this->mostRecentQuoteTimestamp($quote);
                if (! $mostRecentTimestamp || $mostRecentTimestamp->lt(now()->subMinutes(self::QUOTE_FRESH_MINUTES))) {
                    $skippedStaleQuote++;

                    return;
                }

                $priceNow = (float) $quote->price;

                $symbol = $symbolCache[$trade->symbol_code] ?? null;
                if ($symbol === null && ! array_key_exists($trade->symbol_code, $symbolCache)) {
                    $symbol = Symbol::query()
                        ->where('code', $trade->symbol_code)
                        ->first();

                    $symbolCache[$trade->symbol_code] = $symbol;
                }

                if (! $symbol) {
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
                if ($trailingEnabled) {
                    $entry = (float) $trade->entry_price;

                    $activationPoints = $activationPercent > 0
                        ? round((($entry * $activationPercent) / $pointSize), 2)
                        : (float) $activationPointsFallback;

                    $distancePoints = $distancePercent > 0
                        ? round((($entry * $distancePercent) / $pointSize), 2)
                        : (float) $distancePointsFallback;

                    if ($activationPoints > 0 && $distancePoints > 0 && $unrealizedPoints >= $activationPoints) {
                        $side = (string) $trade->side;

                        $existingExitStop = $meta['exit_stop'] ?? null;
                        $existingStopPrice = (is_array($existingExitStop) && isset($existingExitStop['stop_price']))
                            ? (float) $existingExitStop['stop_price']
                            : null;

                        if ($existingStopPrice === null) {
                            $sl = $this->slPoints($trade);
                            $existingStopPrice = $side === 'buy'
                                ? ($entry - ($sl * $pointSize))
                                : ($entry + ($sl * $pointSize));
                        }

                        $distancePrice = $distancePoints * $pointSize;

                        $candidate = $side === 'buy'
                            ? ($priceNow - $distancePrice)
                            : ($priceNow + $distancePrice);

                        $shouldUpdate = $side === 'buy'
                            ? ($candidate > $existingStopPrice)
                            : ($candidate < $existingStopPrice);

                        if ($shouldUpdate) {
                            $exitStop = is_array($existingExitStop) ? $existingExitStop : [];

                            $exitStop['armed_at'] = now()->toDateTimeString();
                            $exitStop['reason'] = 'profit_trailing';
                            $exitStop['stop_price'] = $candidate;

                            $exitStop['trail_activation_percent'] = $activationPercent;
                            $exitStop['trail_distance_percent'] = $distancePercent;
                            $exitStop['trail_activation_points_computed'] = $activationPoints;
                            $exitStop['trail_distance_points_computed'] = $distancePoints;
                            $exitStop['trail_entry_price'] = $entry;
                            $exitStop['trail_point_size'] = $pointSize;

                            $exitStop['trail_price_now_at_update'] = $priceNow;
                            $exitStop['trail_previous_stop_price'] = $existingStopPrice;
                            $exitStop['trail_candidate_stop_price'] = $candidate;

                            $meta['exit_stop'] = $exitStop;

                            $trade->meta = $meta;
                            $trade->save();

                            $exitStopMoved++;
                        }
                    }
                }

                // ------------------------------------------------------------
                // 1) EXIT STOP (close on hit) - execute at LEVEL
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

                        $riskPoints = $this->slPoints($trade);
                        $rMultiple = $riskPoints > 0 ? round($realizedPoints / $riskPoints, 2) : null;

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
                            'r_multiple' => $rMultiple,
                        ];

                        $trade->status = TradeStatus::CLOSED->value;
                        $trade->closed_at = now();
                        $trade->exit_price = $exitPrice;
                        $trade->realized_points = $realizedPoints;
                        $trade->unrealized_points = 0;
                        $trade->meta = $meta;
                        $trade->save();
                        Notification::make()
                            ->title("CLOSE {$trade->symbol_code} {$trade->timeframe_code}")
                            ->body("PnL: {$realizedPoints} ({$unrealizedPoints} pips)")
                            ->warning()
                            ->send();
                        // Send Filament database notification for closed trade
                        $user = User::query()->orderBy('id')->first();
                        if ($user) {

                            Notification::make()
                                ->title("CLOSE {$trade->symbol_code} {$trade->timeframe_code}")
                                ->body("PnL: {$realizedPoints} ({$unrealizedPoints} pips)")
                                ->warning()
                                ->sendToDatabase($user);
                        }

                        $exitStopHit++;
                        $closed++;

                        return;
                    }
                }

                // ------------------------------------------------------------
                // 2) HARD EXITS (SL/TP/Time)
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

                    $riskPoints = $this->slPoints($trade);
                    $rMultiple = $riskPoints > 0 ? round($realizedPoints / $riskPoints, 2) : null;

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
                        'r_multiple' => $rMultiple,
                    ];

                    $trade->status = TradeStatus::CLOSED->value;
                    $trade->closed_at = now();
                    $trade->exit_price = $exitPrice;
                    $trade->realized_points = $realizedPoints;
                    $trade->unrealized_points = 0;
                    $trade->meta = $meta;
                    $trade->save();
                    Notification::make()
                        ->title("CLOSE {$trade->symbol_code} {$trade->timeframe_code}")
                        ->body("PnL: {$realizedPoints} ({$unrealizedPoints} pips)")
                        ->warning()
                        ->send();
                    // Send Filament database notification for closed trade
                    $user = User::query()->orderBy('id')->first();
                    if ($user) {

                        Notification::make()
                            ->title("CLOSE {$trade->symbol_code} {$trade->timeframe_code}")
                            ->body("PnL: {$realizedPoints} ({$unrealizedPoints} pips)")
                            ->warning()
                            ->sendToDatabase($user);
                    }

                    $closed++;

                    return;
                }

                // ------------------------------------------------------------
                // 3) PRICE-ONLY STRATEGY EXIT: window reversal (NO candles)
                // ------------------------------------------------------------
                $tradeTf = (string) $trade->timeframe_code;
                $exitTf = $this->lowerTimeframe($tradeTf);

                if ($exitTf === null) {
                    $held++;

                    return;
                }

                $tfCfg = (array) (config("trading.strategy.price_windows.timeframes.{$exitTf}") ?? []);
                $minutes = (int) ($tfCfg['minutes'] ?? 0);
                $points = (int) ($tfCfg['points'] ?? 0);

                if ($minutes <= 0 || $points <= 0) {
                    $held++;

                    return;
                }

                $w = $this->priceWindows->window(
                    symbolCode: (string) $trade->symbol_code,
                    minutes: $minutes,
                    points: $points,
                    dirFlatThresholdPct: $exitFlatThresholdPct
                );

                $curr = (array) ($w['current'] ?? []);
                $prev = (array) ($w['previous'] ?? []);

                $currOk = (bool) ($curr['is_complete'] ?? false);
                $prevOk = (bool) ($prev['is_complete'] ?? false);

                if (! $currOk || ! $prevOk) {
                    $held++;

                    return;
                }

                $dir = (string) ($w['dir'] ?? 'no_data');
                $dirPct = isset($w['dir_pct']) ? (float) $w['dir_pct'] : null;

                $against = $trade->isLong()
                    ? ($dir === 'down')
                    : ($dir === 'up');

                $strongEnough = $dirPct !== null && $dirPct >= $exitReversalMinStrengthPct;

                if ($against && $strongEnough) {
                    // Close immediately at market (current quote)
                    $exitPrice = $priceNow;

                    $realizedPoints = $this->pointsFromPrices(
                        entryPrice: (float) $trade->entry_price,
                        exitPrice: $exitPrice,
                        pointSize: $pointSize,
                        side: (string) $trade->side
                    );

                    $riskPoints = $this->slPoints($trade);
                    $rMultiple = $riskPoints > 0 ? round($realizedPoints / $riskPoints, 2) : null;

                    $meta['close'] = [
                        'source' => 'trade:close',
                        'reason' => 'price_window_reversal',
                        'quote_pulled_at' => $mostRecentTimestamp->toDateTimeString(),
                        'trade_tf' => $tradeTf,
                        'exit_tf' => $exitTf,
                        'exit_price_mode' => $exitArmingMode,
                        'price_now' => $priceNow,
                        'unrealized_points_at_close' => $unrealizedPoints,
                        'r_multiple' => $rMultiple,
                        'window' => $w,
                        'thresholds' => [
                            'flat_threshold_pct' => $exitFlatThresholdPct,
                            'reversal_min_strength_pct' => $exitReversalMinStrengthPct,
                        ],
                        'strategy_cfg' => [
                            'risk' => $cfg['risk'] ?? null,
                        ],
                    ];

                    $trade->status = TradeStatus::CLOSED->value;
                    $trade->closed_at = now();
                    $trade->exit_price = $exitPrice;
                    $trade->realized_points = $realizedPoints;
                    $trade->unrealized_points = 0;
                    $trade->meta = $meta;
                    $trade->save();
                    Notification::make()
                        ->title("CLOSE {$trade->symbol_code} {$trade->timeframe_code}")
                        ->body("PnL: {$realizedPoints} ({$unrealizedPoints} pips)")
                        ->warning()
                        ->send();
                    // Send Filament database notification for closed trade
                    $user = User::query()->orderBy('id')->first();
                    if ($user) {

                        Notification::make()
                            ->title("CLOSE {$trade->symbol_code} {$trade->timeframe_code}")
                            ->body("PnL: {$realizedPoints} ({$unrealizedPoints} pips)")
                            ->warning()
                            ->sendToDatabase($user);
                    }

                    $closed++;

                    return;
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
            'exit_stop_moved' => $exitStopMoved,
            'exit_stop_hit' => $exitStopHit,
        ];
    }

    private function mostRecentQuoteTimestamp(SymbolQuote $quote): ?Carbon
    {
        if ($quote->pulled_at instanceof Carbon) {
            return $quote->pulled_at;
        }

        if ($quote->pulled_at) {
            return Carbon::parse($quote->pulled_at);
        }

        return null;
    }

    private function pointsFromPrices(float $entryPrice, float $exitPrice, float $pointSize, string $side): float
    {
        if ($pointSize <= 0) {
            return 0.0;
        }

        $diff = ($side === 'buy')
            ? ($exitPrice - $entryPrice)
            : ($entryPrice - $exitPrice);

        return round($diff / $pointSize, 2);
    }

    private function slPoints(Trade $trade): float
    {
        return (float) ($trade->stop_loss_points ?? 0);
    }

    private function tpPoints(Trade $trade): float
    {
        return (float) ($trade->take_profit_points ?? 0);
    }

    private function maxHoldMinutes(Trade $trade): int
    {
        return (int) ($trade->max_hold_minutes ?? 0);
    }

    /**
     * @return array{reason:string, exit_price:float, exit_price_mode:string, exit_price_level:float}|null
     */
    private function detectHardExit(Trade $trade, float $priceNow, float $pointSize): ?array
    {
        $entry = (float) $trade->entry_price;
        $side = (string) $trade->side;

        // Time stop (market)
        $maxHold = $this->maxHoldMinutes($trade);
        if ($maxHold > 0 && $trade->opened_at) {
            $age = now()->diffInMinutes($trade->opened_at);
            if ($age >= $maxHold) {
                return [
                    'reason' => 'time_exit',
                    'exit_price' => $priceNow,
                    'exit_price_mode' => 'market',
                    'exit_price_level' => $priceNow,
                ];
            }
        }

        if ($pointSize <= 0) {
            return null;
        }

        $sl = $this->slPoints($trade);
        if ($sl > 0) {
            $slPrice = $side === 'buy'
                ? ($entry - ($sl * $pointSize))
                : ($entry + ($sl * $pointSize));

            $hit = $side === 'buy'
                ? ($priceNow <= $slPrice)
                : ($priceNow >= $slPrice);

            if ($hit) {
                return [
                    'reason' => 'stop_loss',
                    'exit_price' => $slPrice,
                    'exit_price_mode' => 'level',
                    'exit_price_level' => $slPrice,
                ];
            }
        }

        $tp = $this->tpPoints($trade);
        if ($tp > 0) {
            $tpPrice = $side === 'buy'
                ? ($entry + ($tp * $pointSize))
                : ($entry - ($tp * $pointSize));

            $hit = $side === 'buy'
                ? ($priceNow >= $tpPrice)
                : ($priceNow <= $tpPrice);

            if ($hit) {
                return [
                    'reason' => 'take_profit',
                    'exit_price' => $tpPrice,
                    'exit_price_mode' => 'level',
                    'exit_price_level' => $tpPrice,
                ];
            }
        }

        return null;
    }

    private function lowerTimeframe(string $tradeTf): ?string
    {
        // Desc -> Asc: pick next faster TF
        $order = ['1d', '4h', '1h', '30m', '15m', '5m'];

        $i = array_search($tradeTf, $order, true);
        if ($i === false) {
            return null;
        }

        $next = $order[$i + 1] ?? null;

        return is_string($next) ? $next : null;
    }

    private function notifyProviderError(\Throwable $e, string $symbolCode): void
    {
        $notificationService = app(SignalNotificationService::class);

        $notificationService->notify([
            'type' => 'provider_error',
            'title' => 'Provider error',
            'message' => 'Market data provider request failed',
            'level' => 'warning',
            'symbol' => $symbolCode,
            'timeframe' => null,
            'reason' => $e->getMessage(),
            'happened_at' => now()->toISOString(),
        ]);
        Notification::make()
            ->title('DATA PROVIDER ERROR')
            ->body($e->getMessage())
            ->danger()
            ->send();
        // Send Filament database notification
        $user = User::query()->orderBy('id')->first();
        if ($user) {
            Notification::make()
                ->title('DATA PROVIDER ERROR')
                ->body($e->getMessage())
                ->danger()
                ->sendToDatabase($user)
                ->send();
        }
    }
}
