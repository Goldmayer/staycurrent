<?php

namespace App\Services\Trading;

use App\Contracts\StrategySettingsRepository;
use App\Enums\TradeStatus;
use App\Models\PendingOrder;
use App\Models\Symbol;
use App\Models\SymbolQuote;
use App\Models\Trade;
use App\Models\TradeMonitor;
use App\Models\User;
use App\Services\Notifications\SignalNotificationService;
use Filament\Notifications\Notification;

class TradeTickService
{
    public function __construct(
        private readonly TradeDecisionService $decision,
        private readonly StrategySettingsRepository $settings,
        private readonly FxSessionScheduler $fxSessionScheduler,
        private readonly SignalNotificationService $signalNotification,
    ) {}

    public function process(
        ?int $limit = null,
        ?string $onlySymbol = null,
        bool $forceOpen = false,
        ?string $forceSide = null,
        ?string $forceTimeframe = null,
    ): array {
        $symbolsQuery = Symbol::query()->where('is_active', true);

        if ($onlySymbol) {
            $symbolsQuery->where('code', $onlySymbol);
        }

        if ($limit) {
            $symbolsQuery->limit($limit);
        }

        $symbols = $symbolsQuery->pluck('code')->all();

        return $this->processSymbols(
            symbolCodes: $symbols,
            forceOpen: $forceOpen,
            forceSide: $forceSide,
            forceTimeframe: $forceTimeframe,
        );
    }

    /**
     * @param  array<int, string>  $symbolCodes
     */
    public function processSymbols(
        array $symbolCodes,
        bool $forceOpen = false,
        ?string $forceSide = null,
        ?string $forceTimeframe = null,
    ): array {
        $symbolCodes = array_values(array_unique(array_filter(array_map(
            fn ($s) => strtoupper(trim((string) $s)),
            $symbolCodes
        ), fn ($s) => $s !== '')));

        $symbolsProcessed = 0;
        $tradesOpened = 0;
        $tradesSkipped = 0;
        $pendingsCreated = 0;
        $pendingsFilled = 0;
        $pendingsCancelled = 0;

        $skipped = [
            'missing_quote' => 0,
            'decision_hold' => 0,
            'existing_open_trade' => 0,
            'invalid_point_size' => 0,
            'invalid_force_params' => 0,
            'session_closed' => 0,
        ];

        if ($symbolCodes === []) {
            return [
                'symbols_processed' => 0,
                'trades_opened' => 0,
                'trades_skipped' => 0,
                'pendings_created' => 0,
                'pendings_filled' => 0,
                'pendings_cancelled' => 0,
                'skipped' => $skipped,
            ];
        }

        $symbols = Symbol::query()
            ->where('is_active', true)
            ->whereIn('code', $symbolCodes)
            ->get();

        if ($symbols->isEmpty()) {
            return [
                'symbols_processed' => 0,
                'trades_opened' => 0,
                'trades_skipped' => 0,
                'pendings_created' => 0,
                'pendings_filled' => 0,
                'pendings_cancelled' => 0,
                'skipped' => $skipped,
            ];
        }

        $symbolList = $symbols->pluck('code')->all();

        $quotes = SymbolQuote::query()
            ->whereIn('symbol_code', $symbolList)
            ->get()
            ->keyBy('symbol_code');

        $openTrades = Trade::query()
            ->whereIn('symbol_code', $symbolList)
            ->where('status', TradeStatus::OPEN->value)
            ->get(['id', 'symbol_code', 'timeframe_code']);

        $openTradeSet = [];
        $hasAnyOpenTradeBySymbol = [];

        foreach ($openTrades as $t) {
            $k = (string) $t->symbol_code.'|'.(string) $t->timeframe_code;
            $openTradeSet[$k] = (int) $t->id;
            $hasAnyOpenTradeBySymbol[(string) $t->symbol_code] = true;
        }

        // Get existing pending orders for these symbols
        $existingPendings = PendingOrder::query()
            ->whereIn('symbol_code', $symbolList)
            ->get()
            ->keyBy(function ($pending) {
                return $pending->symbol_code.'|'.$pending->timeframe_code;
            });

        $risk = $this->settings->get()['risk'] ?? [];
        $entry = $this->settings->get()['entry'] ?? [];

        $slPercent = (float) ($risk['stop_loss_percent'] ?? 0.003);
        $tpPercent = (float) ($risk['take_profit_percent'] ?? 0.0);
        $maxHoldCfg = (int) ($risk['max_hold_minutes'] ?? 120);
        $pendingDistancePoints = (int) ($entry['pending_distance_points'] ?? 10);

        foreach ($symbols as $symbol) {
            $symbolsProcessed++;

            $quote = $quotes[$symbol->code] ?? null;
            $currentPrice = $quote?->price;

            if (! $currentPrice) {
                $tradesSkipped++;
                $skipped['missing_quote']++;

                continue;
            }

            $quotePulledAt = $this->formatQuotePulledAt($quote);

            if ($forceOpen) {
                if (! $forceSide || ! $forceTimeframe) {
                    $tradesSkipped++;
                    $skipped['invalid_force_params']++;

                    continue;
                }

                $decision = [
                    'action' => 'open',
                    'side' => $forceSide,
                    'timeframe_code' => $forceTimeframe,
                    'reason' => 'forced_open',
                    'debug' => [
                        'forced' => true,
                        'force_side' => $forceSide,
                        'force_tf' => $forceTimeframe,
                    ],
                ];
            } else {
                $decision = $this->decision->decideOpen($symbol->code);
            }

            // ✅ Persist WAIT:* statuses for symbols even if they have open trades on OTHER TFs.
            // This only touches open_trade_id IS NULL rows, so it won't overwrite "open trade" monitors.
            if (! $forceOpen) {
                $this->persistWaitingMonitorsFromDecision($symbol->code, $decision);
            }

            // Check if we're in a trading window for new entries
            if (! $forceOpen && ! $this->fxSessionScheduler->isInTradingWindow($symbol->code, now())) {
                $tradesSkipped++;
                $skipped['session_closed']++;

                continue;
            }

            if (($decision['action'] ?? 'hold') !== 'open') {
                // Cancel pending orders for this symbol when decision is 'hold'
                $cancelled = $this->cancelPendingOrdersForSymbol($symbol->code, $existingPendings);
                $pendingsCancelled += $cancelled;

                $tradesSkipped++;
                $skipped['decision_hold']++;

                continue;
            }

            $timeframeCode = (string) ($decision['timeframe_code'] ?? '');
            $side = (string) ($decision['side'] ?? '');

            $openKey = $symbol->code.'|'.$timeframeCode;
            if (isset($openTradeSet[$openKey])) {
                $tradesSkipped++;
                $skipped['existing_open_trade']++;

                continue;
            }

            $pointSize = (float) $symbol->point_size;
            if ($pointSize <= 0) {
                $tradesSkipped++;
                $skipped['invalid_point_size']++;

                continue;
            }

            // Check if pending order exists and fill it if price is reached
            $pendingKey = $symbol->code.'|'.$timeframeCode;
            $existingPending = $existingPendings[$pendingKey] ?? null;

            if ($existingPending) {
                $filled = $this->tryFillPendingOrder($existingPending, $currentPrice, $symbol, $decision, $risk);
                if ($filled) {
                    $pendingsFilled++;
                    $tradesOpened++;

                    // Remove from existing pendings since it was filled
                    unset($existingPendings[$pendingKey]);

                    // ✅ If we opened trade on TF, ensure any WAIT:* monitor for that TF is cleared (open_trade_id is NULL monitors)
                    if (! $forceOpen) {
                        $this->clearWaitingMonitorForSymbolTf($symbol->code, $timeframeCode);
                    }

                    continue;
                }
            }

            // Create or update pending order (ONLY when something meaningful changed)
            $changed = $this->ensurePendingOrder(
                symbol: $symbol,
                timeframeCode: $timeframeCode,
                side: $side,
                currentPrice: (float) $currentPrice,
                pointSize: $pointSize,
                pendingDistancePoints: $pendingDistancePoints,
                decision: $decision
            );

            if ($changed) {
                $pendingsCreated++;
            }

            // Update existing pendings collection if we created/updated
            if ($changed) {
                $existingPendings[$pendingKey] = PendingOrder::query()
                    ->where('symbol_code', $symbol->code)
                    ->where('timeframe_code', $timeframeCode)
                    ->first();
            }
        }

        return [
            'symbols_processed' => $symbolsProcessed,
            'trades_opened' => $tradesOpened,
            'trades_skipped' => $tradesSkipped,
            'pendings_created' => $pendingsCreated,
            'pendings_filled' => $pendingsFilled,
            'pendings_cancelled' => $pendingsCancelled,
            'skipped' => $skipped,
        ];
    }

    private function formatQuotePulledAt(?SymbolQuote $quote): ?string
    {
        if (! $quote) {
            return null;
        }
        $val = $quote->pulled_at ?? $quote->updated_at ?? null;

        return $val instanceof \DateTimeInterface ? $val->format('Y-m-d H:i:s') : (string) $val;
    }

    /**
     * Persist WAIT:* statuses for "waiting_lower_reversal" (open_trade_id must stay NULL).
     * Also clears stale WAIT:* statuses for this symbol when waiting is no longer present.
     */
    private function persistWaitingMonitorsFromDecision(string $symbolCode, array $decision): void
    {
        $reason = (string) ($decision['reason'] ?? '');
        $debug = (array) ($decision['debug'] ?? []);
        $waiting = (array) ($debug['waiting_entries'] ?? []);

        if ($reason !== 'waiting_lower_reversal' || $waiting === []) {
            // Clear only WAIT:* (do not touch other statuses)
            TradeMonitor::query()
                ->where('symbol_code', $symbolCode)
                ->whereNull('open_trade_id')
                ->where('expectation', 'like', 'WAIT:%')
                ->update(['expectation' => null]);

            return;
        }

        $waitingTfs = [];

        foreach ($waiting as $w) {
            $entryTf = (string) ($w['entry_tf'] ?? '');
            if ($entryTf === '') {
                continue;
            }

            $waitingTfs[] = $entryTf;

            $wanted = strtoupper((string) ($w['wanted_dir'] ?? ''));
            $lowerNow = strtoupper((string) ($w['lower_dir_now'] ?? ''));
            $lowerTf = (string) ($w['lower_tf'] ?? '');
            $req = (string) ($w['required_seniors'] ?? '');
            $cnt = (string) ($w['seniors_in_dir_count'] ?? '');

            $expectation = "WAIT: entry={$entryTf} lower={$lowerTf} want={$wanted} lower_now={$lowerNow} seniors={$cnt}/{$req}";

            $monitor = TradeMonitor::updateOrCreate(
                [
                    'symbol_code' => $symbolCode,
                    'timeframe_code' => $entryTf,
                ],
                [
                    'open_trade_id' => null,
                    'expectation' => $expectation,
                ]
            );

            // Notify if state changed
            if ($monitor->wasChanged('expectation') && $monitor->last_notified_state !== $expectation) {
                $this->signalNotification->notify([
                    'type' => 'waiting',
                    'title' => 'Waiting for entry',
                    'message' => "Waiting for {$symbolCode} {$entryTf} to confirm reversal",
                    'level' => 'info',
                    'symbol' => $symbolCode,
                    'timeframe' => $entryTf,
                    'reason' => $expectation,
                    'happened_at' => now()->toISOString(),
                ]);

                // Send Filament database notification
                $user = User::query()->orderBy('id')->first();
                if ($user) {
                    Notification::make()
                        ->title("WAITING {$symbolCode} {$entryTf}")
                        ->body("Reason: {$expectation}")
                        ->sendToDatabase($user);
                }

                $monitor->last_notified_state = $expectation;
                $monitor->save();
            }
        }

        $waitingTfs = array_values(array_unique(array_filter($waitingTfs, fn ($x) => is_string($x) && $x !== '')));

        // Clear stale WAIT:* rows for this symbol not in current waiting list
        TradeMonitor::query()
            ->where('symbol_code', $symbolCode)
            ->whereNull('open_trade_id')
            ->where('expectation', 'like', 'WAIT:%')
            ->when($waitingTfs !== [], fn ($q) => $q->whereNotIn('timeframe_code', $waitingTfs))
            ->update(['expectation' => null]);
    }

    private function clearWaitingMonitorForSymbolTf(string $symbolCode, string $timeframeCode): void
    {
        TradeMonitor::query()
            ->where('symbol_code', $symbolCode)
            ->where('timeframe_code', $timeframeCode)
            ->whereNull('open_trade_id')
            ->where('expectation', 'like', 'WAIT:%')
            ->update(['expectation' => null]);
    }

    private function openTrade(
        string $symbolCode,
        string $timeframeCode,
        string $side,
        float $entryPrice,
        ?string $quotePulledAt,
        int $hash,
        array $decision,
        float $stopLossPoints,
        float $takeProfitPoints,
        int $maxHoldMinutes,
        float $pointSize,
        float $stopLossPercent,
        float $takeProfitPercent,
        bool $forced
    ): int {
        $trade = Trade::create([
            'symbol_code' => $symbolCode,
            'timeframe_code' => $timeframeCode,
            'side' => $side,
            'status' => TradeStatus::OPEN->value,
            'entry_price' => $entryPrice,
            'opened_at' => now(),
            'stop_loss_points' => $stopLossPoints,
            'take_profit_points' => $takeProfitPoints,
            'max_hold_minutes' => $maxHoldMinutes,
            'meta' => [
                'source' => 'trade:tick',
                'open' => [
                    'source' => 'trade:tick',
                    'forced' => $forced,
                    'reason' => (string) ($decision['reason'] ?? 'strategy_entry'),
                    'timeframe' => $timeframeCode,
                    'quote_pulled_at' => $quotePulledAt,
                    'hash' => $hash,
                    'decision' => $decision,
                ],
                'risk' => [
                    'stop_loss_points' => $stopLossPoints,
                    'take_profit_points' => $takeProfitPoints,
                    'max_hold_minutes' => $maxHoldMinutes,
                    'stop_loss_percent' => $stopLossPercent,
                    'take_profit_percent' => $takeProfitPercent,
                    'point_size' => $pointSize,
                ],
            ],
        ]);

        // Send Filament database notification for opened trade
        $user = User::query()->orderBy('id')->first();
        if ($user) {
            Notification::make()
                ->title("OPEN {$trade->symbol_code} {$trade->timeframe_code}")
                ->body("Side: {$trade->side} | Price: {$trade->entry_price}")
                ->success()
                ->sendToDatabase($user);
        }

        return (int) $trade->id;
    }

    /**
     * Ensure a pending order exists for the given symbol/timeframe/side.
     * Creates new or updates existing pending order ONLY when side/entry_price changes.
     */
    private function ensurePendingOrder(
        Symbol $symbol,
        string $timeframeCode,
        string $side,
        float $currentPrice,
        float $pointSize,
        int $pendingDistancePoints,
        array $decision
    ): bool {
        $entryPrice = $this->calculatePendingEntryPrice($side, $currentPrice, $pointSize, $pendingDistancePoints);

        /** @var PendingOrder|null $pending */
        $pending = PendingOrder::query()
            ->where('symbol_code', $symbol->code)
            ->where('timeframe_code', $timeframeCode)
            ->first();

        // Create
        if (! $pending) {
            $pending = PendingOrder::create([
                'symbol_code' => $symbol->code,
                'timeframe_code' => $timeframeCode,
                'side' => $side,
                'entry_price' => $entryPrice,
                'meta' => [
                    'created_at' => now()->toISOString(),
                    'decision' => $decision,
                    'point_size' => $pointSize,
                    'pending_distance_points' => $pendingDistancePoints,
                ],
            ]);

            $this->signalNotification->notify([
                'type' => 'pending_created',
                'title' => 'Pending order created',
                'message' => "Pending {$symbol->code} {$timeframeCode} {$side} at {$entryPrice}",
                'level' => 'info',
                'symbol' => $symbol->code,
                'timeframe' => $timeframeCode,
                'reason' => "Entry: {$entryPrice}",
                'happened_at' => now()->toISOString(),
            ]);

            $user = User::query()->orderBy('id')->first();
            if ($user) {
                Notification::make()
                    ->title("PENDING {$symbol->code} {$timeframeCode}")
                    ->body("Side: {$side} | Entry: {$entryPrice}")
                    ->info()
                    ->sendToDatabase($user);
            }

            return true;
        }

        // Update ONLY if meaningful fields changed (avoid meta churn/spam)
        $existingSide = (string) $pending->side;
        $existingEntry = (float) $pending->entry_price;

        $entryChanged = round($existingEntry, 8) !== round((float) $entryPrice, 8);
        $sideChanged = $existingSide !== $side;

        if (! $entryChanged && ! $sideChanged) {
            return false;
        }

        $prevEntry = $existingEntry;
        $prevSide = $existingSide;

        $pending->side = $side;
        $pending->entry_price = $entryPrice;
        $pending->save();

        $this->signalNotification->notify([
            'type' => 'pending_updated',
            'title' => 'Pending order updated',
            'message' => "Pending {$symbol->code} {$timeframeCode} {$side} at {$entryPrice}",
            'level' => 'info',
            'symbol' => $symbol->code,
            'timeframe' => $timeframeCode,
            'reason' => "Prev: {$prevSide} {$prevEntry} -> New: {$side} {$entryPrice}",
            'happened_at' => now()->toISOString(),
        ]);

        $user = User::query()->orderBy('id')->first();
        if ($user) {
            Notification::make()
                ->title("PENDING {$symbol->code} {$timeframeCode}")
                ->body("Side: {$side} | Entry: {$entryPrice}")
                ->info()
                ->sendToDatabase($user);
        }

        return true;
    }

    /**
     * Calculate pending entry price based on current price and distance.
     */
    private function calculatePendingEntryPrice(string $side, float $currentPrice, float $pointSize, int $pendingDistancePoints): float
    {
        if ($side === 'buy') {
            return $currentPrice + ($pendingDistancePoints * $pointSize);
        } elseif ($side === 'sell') {
            return $currentPrice - ($pendingDistancePoints * $pointSize);
        }

        return $currentPrice;
    }

    /**
     * Try to fill a pending order if current price reaches entry price.
     */
    private function tryFillPendingOrder(
        PendingOrder $pending,
        float $currentPrice,
        Symbol $symbol,
        array $decision,
        array $risk
    ): bool {
        $entryPrice = (float) $pending->entry_price;
        $side = (string) $pending->side;

        // Check if price has reached entry level
        $shouldFill = false;

        if ($side === 'buy' && $currentPrice >= $entryPrice) {
            $shouldFill = true;
        } elseif ($side === 'sell' && $currentPrice <= $entryPrice) {
            $shouldFill = true;
        }

        if (! $shouldFill) {
            return false;
        }

        // Calculate risk parameters
        $fallbackSlPoints = (float) ($risk['stop_loss_points'] ?? 20);
        $fallbackTpPoints = (float) ($risk['take_profit_points'] ?? 0);
        $slPercent = (float) ($risk['stop_loss_percent'] ?? 0.003);
        $tpPercent = (float) ($risk['take_profit_percent'] ?? 0.0);
        $maxHoldCfg = (int) ($risk['max_hold_minutes'] ?? 120);

        $stopLossPoints = $slPercent > 0
            ? round(($entryPrice * $slPercent) / $symbol->point_size, 2)
            : $fallbackSlPoints;

        $takeProfitPoints = $tpPercent > 0
            ? round(($entryPrice * $tpPercent) / $symbol->point_size, 2)
            : $fallbackTpPoints;

        if ($stopLossPoints <= 0) {
            $stopLossPoints = $fallbackSlPoints;
        }
        if ($takeProfitPoints <= 0) {
            $takeProfitPoints = $fallbackTpPoints;
        }

        $hash = crc32($symbol->code.'|'.$pending->timeframe_code.'|'.$side);

        // Open the trade
        $tradeId = $this->openTrade(
            symbolCode: $symbol->code,
            timeframeCode: $pending->timeframe_code,
            side: $side,
            entryPrice: $entryPrice,
            quotePulledAt: null, // Will be set in openTrade
            hash: $hash,
            decision: $decision,
            stopLossPoints: $stopLossPoints,
            takeProfitPoints: $takeProfitPoints,
            maxHoldMinutes: $maxHoldCfg,
            pointSize: (float) $symbol->point_size,
            stopLossPercent: $slPercent,
            takeProfitPercent: $tpPercent,
            forced: false
        );

        // Delete the pending order
        $pending->delete();

        return true;
    }

    /**
     * Cancel pending orders for a symbol when decision is 'hold'.
     */
    private function cancelPendingOrdersForSymbol(string $symbolCode, &$existingPendings): int
    {
        $cancelled = 0;

        $pendings = PendingOrder::query()
            ->where('symbol_code', $symbolCode)
            ->get();

        foreach ($pendings as $pending) {
            $pending->delete();
            $cancelled++;

            // Remove from existing pendings collection
            $key = $pending->symbol_code.'|'.$pending->timeframe_code;
            unset($existingPendings[$key]);
        }

        if ($cancelled > 0) {
            // Notify about cancellation
            $this->signalNotification->notify([
                'type' => 'pending_cancelled',
                'title' => 'Pending orders cancelled',
                'message' => "Cancelled {$cancelled} pending orders for {$symbolCode}",
                'level' => 'warning',
                'symbol' => $symbolCode,
                'timeframe' => null,
                'reason' => 'Decision changed to hold',
                'happened_at' => now()->toISOString(),
            ]);

            // Send Filament database notification
            $user = User::query()->orderBy('id')->first();
            if ($user) {
                Notification::make()
                    ->title("PENDING CANCELLED {$symbolCode}")
                    ->body("Cancelled {$cancelled} pending orders")
                    ->warning()
                    ->sendToDatabase($user);
            }
        }

        return $cancelled;
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

        // Send Filament database notification
        $user = User::query()->orderBy('id')->first();
        if ($user) {
            Notification::make()
                ->title('DATA PROVIDER ERROR')
                ->body($e->getMessage())
                ->danger()
                ->sendToDatabase($user);
        }
    }
}
