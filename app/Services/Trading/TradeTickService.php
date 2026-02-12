<?php

namespace App\Services\Trading;

use App\Contracts\StrategySettingsRepository;
use App\Enums\TradeStatus;
use App\Models\Symbol;
use App\Models\SymbolQuote;
use App\Models\Trade;
use App\Models\TradeMonitor;
use App\Models\User;
use App\Services\Notifications\SignalNotificationService;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Log;

class TradeTickService
{
    public function __construct(
        private readonly TradeDecisionService $decision,
        private readonly StrategySettingsRepository $settings,
        private readonly FxSessionScheduler $fxSessionScheduler,
        private readonly SignalNotificationService $signalNotification,
    ) {
    }

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
     * @param array<int, string> $symbolCodes
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
            $k = (string) $t->symbol_code . '|' . (string) $t->timeframe_code;
            $openTradeSet[$k] = (int) $t->id;
            $hasAnyOpenTradeBySymbol[(string) $t->symbol_code] = true;
        }

        $risk = $this->settings->get()['risk'] ?? [];

        $slPercent = (float) ($risk['stop_loss_percent'] ?? 0.003);
        $tpPercent = (float) ($risk['take_profit_percent'] ?? 0.0);
        $maxHoldCfg = (int) ($risk['max_hold_minutes'] ?? 120);

        foreach ($symbols as $symbol) {
            $symbolsProcessed++;

            $quote = $quotes[$symbol->code] ?? null;
            $currentPrice = $quote?->price;

            if (!$currentPrice) {
                $tradesSkipped++;
                $skipped['missing_quote']++;
                continue;
            }

            $entryPrice = (float) $currentPrice;
            $quotePulledAt = $this->formatQuotePulledAt($quote);

            if ($forceOpen) {
                if (!$forceSide || !$forceTimeframe) {
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

            // ✅ Persist WAIT:* monitors for symbols even if they have open trades on OTHER TFs.
            // This only touches open_trade_id IS NULL rows, so it won't overwrite "open trade" monitors.
            if (!$forceOpen) {
                $this->persistWaitingMonitorsFromDecision($symbol->code, $decision);
            }

            // Check if we're in a trading window for new entries
            if (!$forceOpen && !$this->fxSessionScheduler->isInTradingWindow($symbol->code, now())) {
                $tradesSkipped++;
                $skipped['session_closed']++;
                continue;
            }

            if (($decision['action'] ?? 'hold') !== 'open') {
                $tradesSkipped++;
                $skipped['decision_hold']++;
                continue;
            }

            $timeframeCode = (string) ($decision['timeframe_code'] ?? '');
            $side = (string) ($decision['side'] ?? '');

            $openKey = $symbol->code . '|' . $timeframeCode;
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

            $fallbackSlPoints = (float) ($risk['stop_loss_points'] ?? 20);
            $fallbackTpPoints = (float) ($risk['take_profit_points'] ?? 0);

            $stopLossPoints = $slPercent > 0
                ? round(($entryPrice * $slPercent) / $pointSize, 2)
                : $fallbackSlPoints;

            $takeProfitPoints = $tpPercent > 0
                ? round(($entryPrice * $tpPercent) / $pointSize, 2)
                : $fallbackTpPoints;

            if ($stopLossPoints <= 0) {
                $stopLossPoints = $fallbackSlPoints;
            }
            if ($takeProfitPoints <= 0) {
                $takeProfitPoints = $fallbackTpPoints;
            }

            $hash = crc32($symbol->code . '|' . $timeframeCode . '|' . $side);

            $tradeId = $this->openTrade(
                symbolCode: $symbol->code,
                timeframeCode: $timeframeCode,
                side: $side,
                entryPrice: $entryPrice,
                quotePulledAt: $quotePulledAt,
                hash: $hash,
                decision: $decision,
                stopLossPoints: $stopLossPoints,
                takeProfitPoints: $takeProfitPoints,
                maxHoldMinutes: $maxHoldCfg,
                pointSize: $pointSize,
                stopLossPercent: $slPercent,
                takeProfitPercent: $tpPercent,
                forced: $forceOpen
            );

            // ✅ If we opened trade on TF, ensure any WAIT:* monitor for that TF is cleared (open_trade_id is NULL monitors)
            if (!$forceOpen) {
                $this->clearWaitingMonitorForSymbolTf($symbol->code, $timeframeCode);
            }

            // update sets to prevent duplicates within same batch run
            $openTradeSet[$openKey] = $tradeId;
            $hasAnyOpenTradeBySymbol[$symbol->code] = true;

            $tradesOpened++;
        }

        return [
            'symbols_processed' => $symbolsProcessed,
            'trades_opened' => $tradesOpened,
            'trades_skipped' => $tradesSkipped,
            'skipped' => $skipped,
        ];
    }

    private function formatQuotePulledAt(?SymbolQuote $quote): ?string
    {
        if (!$quote) return null;
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
                ->title("DATA PROVIDER ERROR")
                ->body($e->getMessage())
                ->danger()
                ->sendToDatabase($user);
        }
    }
}
