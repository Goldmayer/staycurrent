<?php

namespace App\Services\Trading;

use App\Contracts\StrategySettingsRepository;
use App\Enums\TradeStatus;
use App\Models\Symbol;
use App\Models\SymbolQuote;
use App\Models\Trade;
use App\Models\TradeMonitor;
use App\Services\Notifications\SignalNotificationService;

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
        foreach ($openTrades as $t) {
            $k = (string) $t->symbol_code.'|'.(string) $t->timeframe_code;
            $openTradeSet[$k] = (int) $t->id;
        }

        $existingPendings = Trade::query()
                                 ->whereIn('symbol_code', $symbolList)
                                 ->where('status', 'pending')
                                 ->get(['id', 'symbol_code', 'timeframe_code', 'side', 'entry_price', 'meta', 'status'])
                                 ->keyBy(fn ($trade) => $trade->symbol_code.'|'.$trade->timeframe_code)->all();

        $settings = $this->settings->get();
        $risk = (array) ($settings['risk'] ?? []);
        $entry = (array) ($settings['entry'] ?? []);

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

            // 1) Force open (market)
            if ($forceOpen) {
                $fs = $forceSide ? strtolower(trim((string) $forceSide)) : null;
                $ftf = $forceTimeframe ? strtolower(trim((string) $forceTimeframe)) : null;

                if (! $fs || ! in_array($fs, ['buy', 'sell'], true) || ! $ftf) {
                    $tradesSkipped++;
                    $skipped['invalid_force_params']++;

                    continue;
                }

                $timeframeCode = (string) $ftf;
                $side = (string) $fs;

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

                $entryPrice = (float) $currentPrice;

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

                $decision = [
                    'action' => 'open',
                    'side' => $side,
                    'timeframe_code' => $timeframeCode,
                    'reason' => 'forced_open',
                    'debug' => [
                        'forced' => true,
                        'force_side' => $side,
                        'force_tf' => $timeframeCode,
                    ],
                ];

                $hash = crc32($symbol->code.'|'.$timeframeCode.'|'.$side.'|forced');

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
                    forced: true
                );

                $tradesOpened++;
                $openTradeSet[$openKey] = $tradeId;

                $this->clearWaitingMonitorForSymbolTf($symbol->code, $timeframeCode);

                continue;
            }

            // 2) Normal decision path
            $decision = $this->decision->decideOpen($symbol->code);

            // WAIT monitors (UI only)
            $this->persistWaitingMonitorsFromDecision($symbol->code, $decision);

            // 3) Always try to fill existing pending trades (independent of decision)
            $pendingsForSymbol = collect($existingPendings)
                ->filter(fn ($p) => (string) $p->symbol_code === (string) $symbol->code);

            foreach ($pendingsForSymbol as $pendingKey => $pending) {
                $decisionForFill = (array) (data_get($pending->meta, 'decision') ?? []);
                if ($decisionForFill === []) {
                    $decisionForFill = ['reason' => 'pending_fill'];
                }

                $filled = $this->tryFillPendingTrade(
                    pending: $pending,
                    currentPrice: (float) $currentPrice,
                    symbol: $symbol,
                    decision: $decisionForFill,
                    risk: $risk,
                    quotePulledAt: $quotePulledAt
                );

                if ($filled) {
                    $pendingsFilled++;
                    $tradesOpened++;

                    unset($existingPendings[$pendingKey]);

                    $ok = $symbol->code.'|'.(string) $pending->timeframe_code;
                    $openTradeSet[$ok] = (int) $pending->id;

                    $this->clearWaitingMonitorForSymbolTf($symbol->code, (string) $pending->timeframe_code);
                }
            }

            // 4) If not in trading window, do not create/update NEW pendings
            if (! $this->fxSessionScheduler->isInTradingWindow($symbol->code, now())) {
                $tradesSkipped++;
                $skipped['session_closed']++;

                continue;
            }

            // 5) Cancel pendings by strategy signal (only when decision is not open)
            if (($decision['action'] ?? 'hold') !== 'open') {
                $reason = (string) ($decision['reason'] ?? '');

                if ($reason === 'waiting_m5_reversal') {
                    $wantedDir = (string) ($decision['debug']['wanted_dir'] ?? '');

                    if ($wantedDir === 'up') {
                        $pendingsCancelled += $this->cancelPendingBySide($symbol->code, 'buy', $existingPendings);
                    } elseif ($wantedDir === 'down') {
                        $pendingsCancelled += $this->cancelPendingBySide($symbol->code, 'sell', $existingPendings);
                    }
                }

                $tradesSkipped++;
                $skipped['decision_hold']++;

                continue;
            }

            // 6) Decision says "open" => we CREATE/UPDATE pending trade (not market open)
            $timeframeCode = (string) ($decision['timeframe_code'] ?? '');
            $side = (string) ($decision['side'] ?? '');

            if ($timeframeCode === '' || $side === '') {
                $tradesSkipped++;
                $skipped['decision_hold']++;

                continue;
            }

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

            $pendingKey = $symbol->code.'|'.$timeframeCode;

            $createdOrUpdated = $this->ensurePendingTrade(
                symbol: $symbol,
                timeframeCode: $timeframeCode,
                side: $side,
                currentPrice: (float) $currentPrice,
                pointSize: $pointSize,
                pendingDistancePoints: $pendingDistancePoints,
                decision: $decision
            );

            if ($createdOrUpdated) {
                $pendingsCreated++;

                $existingPendings[$pendingKey] = Trade::query()
                                                      ->where('symbol_code', $symbol->code)
                                                      ->where('timeframe_code', $timeframeCode)
                                                      ->where('status', 'pending')
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

        return $val instanceof \DateTimeInterface ? $val->format('Y-m-d H:i:s') : null;
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

        $this->signalNotification->notify([
            'title' => "OPEN {$trade->symbol_code} {$trade->timeframe_code}",
            'message' => "Side: {$trade->side} | Price: {$trade->entry_price}",
            'level' => 'success',
        ]);

        return (int) $trade->id;
    }

    private function calculatePendingEntryPrice(string $side, float $currentPrice, float $pointSize, int $pendingDistancePoints): float
    {
        return match ($side) {
            'buy' => $currentPrice + ($pendingDistancePoints * $pointSize),
            'sell' => $currentPrice - ($pendingDistancePoints * $pointSize),
            default => $currentPrice,
        };
    }

    private function stableDecisionHash(array $decision): int
    {
        $core = $decision;
        unset($core['debug']);

        $json = json_encode($core, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return (int) crc32($json ?: '');
    }

    private function ensurePendingTrade(
        Symbol $symbol,
        string $timeframeCode,
        string $side,
        float $currentPrice,
        float $pointSize,
        int $pendingDistancePoints,
        array $decision
    ): bool {
        $decisionHash = $this->stableDecisionHash($decision);

        $existing = Trade::query()
                         ->where('symbol_code', $symbol->code)
                         ->where('timeframe_code', $timeframeCode)
                         ->where('status', 'pending')
                         ->first();

        $newMeta = [
            'source' => 'pending_pipeline',
            'decision' => $decision,
            'decision_hash' => $decisionHash,
            'point_size' => $pointSize,
            'pending_distance_points' => $pendingDistancePoints,
        ];

        if (! $existing) {
            $entryPrice = $this->calculatePendingEntryPrice($side, $currentPrice, $pointSize, $pendingDistancePoints);

            Trade::create([
                'symbol_code' => $symbol->code,
                'timeframe_code' => $timeframeCode,
                'side' => $side,
                'status' => 'pending',
                'entry_price' => $entryPrice,
                'opened_at' => null,
                'meta' => $newMeta,
            ]);

            $this->signalNotification->notify([
                'title' => 'Pending trade created',
                'message' => "Pending {$symbol->code} {$timeframeCode} {$side} at {$entryPrice}",
                'level' => 'info',
            ]);

            return true;
        }

        $changed = false;

        $prevMeta = (array) ($existing->meta ?? []);
        $prevDecisionHash = (int) ($prevMeta['decision_hash'] ?? 0);

        if ((string) $existing->side !== $side) {
            $existing->side = $side;
            $existing->entry_price = $this->calculatePendingEntryPrice($side, $currentPrice, $pointSize, $pendingDistancePoints);
            $changed = true;
        }

        if ($prevDecisionHash !== $decisionHash) {
            $existing->meta = $newMeta;
            $changed = true;
        }

        if (! $changed) {
            return false;
        }

        $existing->opened_at = null;
        $existing->save();

        $entryPrice = (float) $existing->entry_price;

        $this->signalNotification->notify([
            'title' => 'Pending trade updated',
            'message' => "Pending {$symbol->code} {$timeframeCode} {$existing->side} at {$entryPrice}",
            'level' => 'info',
        ]);

        return true;
    }

    private function tryFillPendingTrade(
        Trade $pending,
        float $currentPrice,
        Symbol $symbol,
        array $decision,
        array $risk,
        ?string $quotePulledAt
    ): bool {
        $rawStatus = (string) ($pending->getRawOriginal('status') ?? '');
        if ($rawStatus !== 'pending') {
            return false;
        }

        $rawEntry = (string) ($pending->getRawOriginal('entry_price') ?? '');
        $entryPrice = (float) $rawEntry;

        $side = (string) ($pending->getRawOriginal('side') ?? $pending->side ?? '');

        $shouldFill = match ($side) {
            'buy' => $currentPrice >= $entryPrice,
            'sell' => $currentPrice <= $entryPrice,
            default => false,
        };

        if (! $shouldFill) {
            return false;
        }

        $pointSize = (float) $symbol->point_size;
        if ($pointSize <= 0) {
            return false;
        }

        $fallbackSlPoints = (float) ($risk['stop_loss_points'] ?? 20);
        $fallbackTpPoints = (float) ($risk['take_profit_points'] ?? 0);

        $slPercent = (float) ($risk['stop_loss_percent'] ?? 0.003);
        $tpPercent = (float) ($risk['take_profit_percent'] ?? 0.0);
        $maxHoldCfg = (int) ($risk['max_hold_minutes'] ?? 120);

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

        $hash = crc32((string) $pending->symbol_code.'|'.(string) $pending->timeframe_code.'|'.$side.'|pending_fill');

        $pending->status = TradeStatus::OPEN->value;
        $pending->opened_at = now();
        $pending->stop_loss_points = $stopLossPoints;
        $pending->take_profit_points = $takeProfitPoints;
        $pending->max_hold_minutes = $maxHoldCfg;

        $pending->meta = [
            'source' => 'trade:tick',
            'open' => [
                'source' => 'trade:tick',
                'forced' => false,
                'reason' => (string) ($decision['reason'] ?? 'pending_fill'),
                'timeframe' => (string) $pending->timeframe_code,
                'quote_pulled_at' => $quotePulledAt,
                'hash' => $hash,
                'decision' => $decision,
            ],
            'risk' => [
                'stop_loss_points' => $stopLossPoints,
                'take_profit_points' => $takeProfitPoints,
                'max_hold_minutes' => $maxHoldCfg,
                'stop_loss_percent' => $slPercent,
                'take_profit_percent' => $tpPercent,
                'point_size' => $pointSize,
            ],
        ];

        $pending->save();

        $this->signalNotification->notify([
            'title' => "OPEN {$pending->symbol_code} {$pending->timeframe_code}",
            'message' => "Side: {$pending->side} | Price: {$pending->entry_price}",
            'level' => 'success',
        ]);

        return true;
    }

    private function cancelPendingBySide(string $symbolCode, string $side, array &$existingPendings): int
    {
        $pendings = Trade::query()
                         ->where('symbol_code', $symbolCode)
                         ->where('side', $side)
                         ->where('status', 'pending')
                         ->get(['id', 'symbol_code', 'timeframe_code']);

        $cancelled = 0;

        foreach ($pendings as $pending) {
            $key = (string) $pending->symbol_code.'|'.(string) $pending->timeframe_code;

            $pending->delete();
            unset($existingPendings[$key]);

            $cancelled++;
        }

        if ($cancelled > 0) {
            $this->signalNotification->notify([
                'title' => "PENDING CANCELLED {$symbolCode}",
                'message' => "Cancelled {$cancelled} {$side} pending trades",
                'level' => 'warning',
            ]);
        }

        return $cancelled;
    }

    private function persistWaitingMonitorsFromDecision(string $symbolCode, array $decision): void
    {
        $reason = (string) ($decision['reason'] ?? '');
        $debug = (array) ($decision['debug'] ?? []);
        $waiting = (array) ($debug['waiting_entries'] ?? []);

        if ($reason !== 'waiting_lower_reversal' || $waiting === []) {
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

            if ($monitor->wasChanged('expectation') && $monitor->last_notified_state !== $expectation) {
                $this->signalNotification->notify([
                    'title' => "WAITING {$symbolCode} {$entryTf}",
                    'message' => "Reason: {$expectation}",
                    'level' => 'info',
                ]);

                $monitor->last_notified_state = $expectation;
                $monitor->save();
            }
        }

        $waitingTfs = array_values(array_unique(array_filter($waitingTfs, fn ($x) => is_string($x) && $x !== '')));

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

    private function notifyProviderError(\Throwable $e, string $symbolCode): void
    {
        $this->signalNotification->notify([
            'title' => 'DATA PROVIDER ERROR',
            'message' => $e->getMessage(),
            'level' => 'danger',
        ]);
    }
}

