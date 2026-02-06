<?php

namespace App\Services\Trading;

use App\Enums\TradeStatus;
use App\Models\Candle;
use App\Models\Symbol;
use App\Models\SymbolQuote;
use App\Models\Trade;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class TradeCloseService
{
    private const MIN_CANDLES_FOR_HA = 3;
    private const QUOTE_FRESH_MINUTES = 10;

    public function process(?int $limit = null): array
    {
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
        $skippedNotEnoughCandles = 0;
        $skippedNoReversal = 0;

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
                &$skippedNotEnoughCandles,
                &$skippedNoReversal,
                &$symbolCache
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

                $pulledAtRaw = $quote->pulled_at ?? $quote->updated_at ?? null;
                $pulledAt = $pulledAtRaw ? Carbon::parse($pulledAtRaw) : null;

                if (!$pulledAt || $pulledAt->lt(now()->subMinutes(self::QUOTE_FRESH_MINUTES))) {
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

                // Update unrealized points (always)
                $unrealizedPoints = $this->pointsFromPrices(
                    entryPrice: (float) $trade->entry_price,
                    exitPrice: $priceNow,
                    pointSize: $pointSize,
                    side: (string) $trade->side
                );

                $trade->unrealized_points = $unrealizedPoints;
                $trade->save();

                // Build HA reversal signal from last CLOSED candles on trade timeframe
                $candles = Candle::query()
                    ->where('symbol_code', $trade->symbol_code)
                    ->where('timeframe_code', $trade->timeframe_code)
                    ->orderByDesc('open_time_ms')
                    ->limit(self::MIN_CANDLES_FOR_HA)
                    ->get();

                if ($candles->count() < self::MIN_CANDLES_FOR_HA) {
                    $skippedNotEnoughCandles++;
                    $held++;
                    return;
                }

                // Chronological order for HA computation
                $c = $candles->reverse()->values();

                $ha = $this->computeHeikinAshiSeries($c->all());

                // We need last 2 HA candles (based on the last 2 base candles)
                $prev = $ha[count($ha) - 2];
                $curr = $ha[count($ha) - 1];

                $prevDir = $this->haDirection($prev['ha_open'], $prev['ha_close']);
                $currDir = $this->haDirection($curr['ha_open'], $curr['ha_close']);

                // Doji/flat HA is not a reversal signal
                if ($prevDir === 'flat' || $currDir === 'flat') {
                    $skippedNoReversal++;
                    $held++;
                    return;
                }

                $isLong = ((string) $trade->side) === 'buy';
                $reversal = $isLong
                    ? ($prevDir === 'up' && $currDir === 'down')
                    : ($prevDir === 'down' && $currDir === 'up');

                if (!$reversal) {
                    $skippedNoReversal++;
                    $held++;
                    return;
                }

                // Close trade by quote price NOW
                $realizedPoints = $this->pointsFromPrices(
                    entryPrice: (float) $trade->entry_price,
                    exitPrice: $priceNow,
                    pointSize: $pointSize,
                    side: (string) $trade->side
                );

                $meta = is_array($trade->meta) ? $trade->meta : [];
                $meta['close'] = [
                    'source' => 'trade:close',
                    'reason' => 'ha_reversal',
                    'quote_pulled_at' => $pulledAt->toDateTimeString(),
                    'timeframe' => (string) $trade->timeframe_code,
                    'ha_prev_dir' => $prevDir,
                    'ha_curr_dir' => $currDir,
                ];

                $trade->status = TradeStatus::CLOSED;
                $trade->closed_at = now();
                $trade->exit_price = $priceNow;
                $trade->realized_points = $realizedPoints;
                $trade->unrealized_points = 0;
                $trade->meta = $meta;
                $trade->save();

                $closed++;
            });
        }

        return [
            'trades_processed' => $processed,
            'trades_closed' => $closed,
            'trades_held' => $held,
            'skipped_missing_quote' => $skippedMissingQuote,
            'skipped_stale_quote' => $skippedStaleQuote,
            'skipped_missing_symbol' => $skippedMissingSymbol,
            'skipped_not_enough_candles' => $skippedNotEnoughCandles,
            'skipped_no_reversal' => $skippedNoReversal,
        ];
    }

    private function pointsFromPrices(float $entryPrice, float $exitPrice, float $pointSize, string $side): float
    {
        $raw = ($side === 'buy')
            ? (($exitPrice - $entryPrice) / $pointSize)
            : (($entryPrice - $exitPrice) / $pointSize);

        return round($raw, 2);
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

    /**
     * @param Candle[] $candles Chronological order
     * @return array<int, array{ha_open: float, ha_close: float}>
     */
    private function computeHeikinAshiSeries(array $candles): array
    {
        $out = [];

        $prevHaOpen = null;
        $prevHaClose = null;

        foreach ($candles as $i => $c) {
            $open = (float) $c->open;
            $high = (float) $c->high;
            $low  = (float) $c->low;
            $close = (float) $c->close;

            $haClose = ($open + $high + $low + $close) / 4.0;

            if ($i === 0) {
                $haOpen = ($open + $close) / 2.0;
            } else {
                $haOpen = ((float) $prevHaOpen + (float) $prevHaClose) / 2.0;
            }

            $out[] = [
                'ha_open' => $haOpen,
                'ha_close' => $haClose,
            ];

            $prevHaOpen = $haOpen;
            $prevHaClose = $haClose;
        }

        return $out;
    }
}
