<?php

namespace App\Services\Trading;

use App\Contracts\StrategySettingsRepository;
use App\Enums\TradeStatus;
use App\Models\Candle;
use App\Models\Symbol;
use App\Models\SymbolQuote;
use App\Models\Trade;

class TradeTickService
{
    private const MIN_CANDLES = 50;

    public function __construct(
        private readonly TradeDecisionService $decision,
        private readonly StrategySettingsRepository $settings,
    ) {
    }

    public function process(?int $limit = null): array
    {
        $symbolsQuery = Symbol::query()->where('is_active', true);

        if ($limit) {
            $symbolsQuery->limit($limit);
        }

        $symbols = $symbolsQuery->get();

        $symbolsProcessed = 0;
        $tradesOpened = 0;
        $tradesSkipped = 0;

        $skipped = [
            'missing_quote' => 0,
            'decision_hold' => 0,
            'not_enough_candles' => 0,
            'existing_open_trade' => 0,
        ];

        $risk = $this->settings->get()['risk'] ?? [];

        $slPercent = (float) ($risk['stop_loss_percent'] ?? 0.003);   // 0.3%
        $tpPercent = (float) ($risk['take_profit_percent'] ?? 0.0);   // 0.0% (disabled by default)
        $maxHoldCfg = (int) ($risk['max_hold_minutes'] ?? 120);

        foreach ($symbols as $symbol) {
            $symbolsProcessed++;

            $quote = $this->getQuote($symbol->code);
            $currentPrice = $quote?->price;

            if (!$currentPrice) {
                $tradesSkipped++;
                $skipped['missing_quote']++;
                continue;
            }

            $entryPrice = (float) $currentPrice;

            $quotePulledAt = $this->formatQuotePulledAt($quote);

            $decision = $this->decision->decideOpen($symbol->code);

            if (($decision['action'] ?? 'hold') !== 'open') {
                $tradesSkipped++;
                $skipped['decision_hold']++;
                continue;
            }

            $timeframeCode = (string) ($decision['timeframe_code'] ?? '');
            $side = (string) ($decision['side'] ?? '');

            if ($timeframeCode === '' || $side === '') {
                $tradesSkipped++;
                $skipped['decision_hold']++;
                continue;
            }

            $candleCount = $this->getCandleCount($symbol->code, $timeframeCode);
            if ($candleCount < self::MIN_CANDLES) {
                $tradesSkipped++;
                $skipped['not_enough_candles']++;
                continue;
            }

            $existingTrade = Trade::query()->where([
                ['symbol_code', '=', $symbol->code],
                ['timeframe_code', '=', $timeframeCode],
                ['status', '=', TradeStatus::OPEN->value],
            ])->first();

            if ($existingTrade) {
                $tradesSkipped++;
                $skipped['existing_open_trade']++;
                continue;
            }

            $pointSize = (float) $symbol->point_size;
            if ($pointSize <= 0) {
                $tradesSkipped++;
                $skipped['invalid_point_size'] = ($skipped['invalid_point_size'] ?? 0) + 1;
                continue;
            }

            // Compute points from percents if percent > 0, else fallback to fixed points
            $fallbackSlPoints = (float) ($risk['stop_loss_points'] ?? 20);
            $fallbackTpPoints = (float) ($risk['take_profit_points'] ?? 0);

            $stopLossPoints = $slPercent > 0
                ? round(($entryPrice * $slPercent) / $pointSize, 2)
                : $fallbackSlPoints;

            $takeProfitPoints = $tpPercent > 0
                ? round(($entryPrice * $tpPercent) / $pointSize, 2)
                : $fallbackTpPoints;

            // Guards: if computed points <= 0, use fallback
            if ($stopLossPoints <= 0) {
                $stopLossPoints = $fallbackSlPoints;
            }
            if ($takeProfitPoints <= 0) {
                $takeProfitPoints = $fallbackTpPoints;
            }

            $hash = crc32($symbol->code . '|' . $timeframeCode . '|' . $side);

            $this->openTrade(
                symbolCode: $symbol->code,
                timeframeCode: $timeframeCode,
                side: $side,
                entryPrice: $entryPrice,
                quotePulledAt: $quotePulledAt,
                hash: $hash,
                candleCount: $candleCount,
                decision: $decision,
                stopLossPoints: $stopLossPoints,
                takeProfitPoints: $takeProfitPoints,
                maxHoldMinutes: $maxHoldCfg,
                pointSize: $pointSize,
                stopLossPercent: $slPercent,
                takeProfitPercent: $tpPercent,
            );

            $tradesOpened++;
        }

        return [
            'symbols_processed' => $symbolsProcessed,
            'trades_opened' => $tradesOpened,
            'trades_skipped' => $tradesSkipped,
            'skipped' => $skipped,
        ];
    }

    private function getQuote(string $symbolCode): ?SymbolQuote
    {
        return SymbolQuote::query()->where('symbol_code', $symbolCode)->first();
    }

    private function getCandleCount(string $symbolCode, string $timeframeCode): int
    {
        return Candle::query()
                     ->where('symbol_code', $symbolCode)
                     ->where('timeframe_code', $timeframeCode)
                     ->count();
    }

    private function formatQuotePulledAt(?SymbolQuote $quote): ?string
    {
        if (!$quote) {
            return null;
        }

        $val = $quote->pulled_at ?? $quote->updated_at ?? null;
        if ($val === null) {
            return null;
        }

        if ($val instanceof \DateTimeInterface) {
            return $val->format('Y-m-d H:i:s');
        }

        return (string) $val;
    }

    private function openTrade(
        string $symbolCode,
        string $timeframeCode,
        string $side,
        float $entryPrice,
        ?string $quotePulledAt,
        int $hash,
        int $candleCount,
        array $decision,
        float $stopLossPoints,
        float $takeProfitPoints,
        int $maxHoldMinutes,
        float $pointSize,
        float $stopLossPercent,
        float $takeProfitPercent,
    ): void {
        Trade::create([
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
                    'reason' => (string) ($decision['reason'] ?? 'strategy_entry'),
                    'timeframe' => $timeframeCode,
                    'quote_pulled_at' => $quotePulledAt,
                    'hash' => $hash,
                    'min_candles_required' => self::MIN_CANDLES,
                    'candles_count_at_open' => $candleCount,
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
    }
}
