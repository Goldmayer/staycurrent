<?php

namespace App\Services\Trading;

use App\Enums\TimeframeCode;
use App\Enums\TradeSide;
use App\Enums\TradeStatus;
use App\Models\Candle;
use App\Models\Symbol;
use App\Models\SymbolQuote;
use App\Models\Trade;
use Illuminate\Support\Facades\DB;
use App\Services\Trading\TradeDecisionService;


class TradeTickService
{
    private readonly TradeDecisionService $decision;

    /**
     * Minimum number of candles required to open a trade
     */
    private const MIN_CANDLES = 50;

    /**
     * Constructor
     */
    public function __construct(TradeDecisionService $decision)
    {
        $this->decision = $decision;
    }

    /**
     * Process trade tick for all active symbols and timeframes
     */
    public function process(?int $limit = null): array
    {
        $symbols = Symbol::where('is_active', true);

        if ($limit) {
            $symbols = $symbols->limit($limit);
        }

        $symbols = $symbols->get();

        $symbolsProcessed = 0;
        $tradesOpened = 0;
        $tradesSkipped = 0;

        foreach ($symbols as $symbol) {
            $symbolsProcessed++;

            foreach (TimeframeCode::cases() as $timeframe) {
                // Check if there's already an OPEN trade for this symbol+timeframe
                $existingTrade = Trade::where([
                    ['symbol_code', '=', $symbol->code],
                    ['timeframe_code', '=', $timeframe->value],
                    ['status', '=', TradeStatus::OPEN->value],
                ])->first();

                if ($existingTrade) {
                    $tradesSkipped++;
                    continue;
                }

                // Check if we have current price and enough candles
                $quote = $this->getQuote($symbol->code);
                $currentPrice = $quote?->price;
                $quotePulledAt = $quote?->pulled_at ? $quote->pulled_at->toDateTimeString() : null;
                $candleCount = $this->getCandleCount($symbol->code, $timeframe->value);

                if ($currentPrice && $candleCount >= self::MIN_CANDLES) {
                    // Use TradeDecisionService to determine action and side
                    $decision = $this->decision->decideOpen($symbol->code, $timeframe->value);

                    // Only open if decision is to open
                    if ($decision['action'] === 'open') {
                        $side = $decision['side'];
                        $hash = crc32($symbol->code . '|' . $timeframe->value);

                        $this->openTrade($symbol->code, $timeframe->value, $side, $currentPrice, $quotePulledAt, $hash, $candleCount, $decision);
                        $tradesOpened++;
                    }
                }
            }
        }

        return [
            'symbols_processed' => $symbolsProcessed,
            'trades_opened' => $tradesOpened,
            'trades_skipped' => $tradesSkipped,
        ];
    }

    /**
     * Get current price for a symbol
     */
    private function getCurrentPrice(string $symbolCode): ?float
    {
        $quote = SymbolQuote::where('symbol_code', $symbolCode)->first();
        return $quote ? $quote->price : null;
    }

    /**
     * Get quote record for a symbol
     */
    private function getQuote(string $symbolCode): ?SymbolQuote
    {
        return SymbolQuote::where('symbol_code', $symbolCode)->first();
    }

    /**
     * Get candle count for a symbol and timeframe
     */
    private function getCandleCount(string $symbolCode, string $timeframeCode): int
    {
        return Candle::where([
            ['symbol_code', '=', $symbolCode],
            ['timeframe_code', '=', $timeframeCode],
        ])->count();
    }

    /**
     * Determine trade side using deterministic logic
     * Alternates based on symbol hash and timeframe to ensure repeatability
     */
    private function determineSide(string $symbolCode, string $timeframeCode): string
    {
        $hash = crc32($symbolCode . '|' . $timeframeCode);
        return ($hash % 2 === 0) ? 'buy' : 'sell';
    }

    /**
     * Open a new trade
     */
    private function openTrade(string $symbolCode, string $timeframeCode, string $side, float $entryPrice, ?string $quotePulledAt, int $hash, int $candleCount, array $decision): void
    {
        Trade::create([
            'symbol_code' => $symbolCode,
            'timeframe_code' => $timeframeCode,
            'side' => $side,
            'status' => TradeStatus::OPEN,
            'opened_at' => now(),
            'entry_price' => $entryPrice,
            'realized_points' => 0,
            'unrealized_points' => 0,
            'meta' => [
                'source' => 'trade:tick',
                'reason' => 'placeholder_signal',
                'timeframe' => $timeframeCode,
                'open' => [
                    'source' => 'trade:tick',
                    'reason' => 'placeholder_signal',
                    'timeframe' => $timeframeCode,
                    'quote_pulled_at' => $quotePulledAt,
                    'deterministic_side_hash' => $hash,
                    'min_candles_required' => self::MIN_CANDLES,
                    'candles_count_at_open' => $candleCount,
                    'decision_action' => $decision['action'],
                    'decision_reason' => $decision['reason'],
                    'decision_ha_dir' => $decision['ha_dir'] ?? null,
                ],
            ],
        ]);
    }
}
