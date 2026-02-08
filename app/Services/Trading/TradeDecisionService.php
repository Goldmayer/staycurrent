<?php

namespace App\Services\Trading;

use App\Contracts\StrategySettingsRepository;
use App\Enums\TimeframeCode;
use App\Models\Candle;

class TradeDecisionService
{
    public function __construct(
        private readonly StrategySettingsRepository $settings,
    ) {
    }

    /**
     * @return array{
     *   action: 'open'|'hold',
     *   reason: string,
     *   side?: 'buy'|'sell',
     *   timeframe_code?: string,
     *   debug?: array<string, mixed>
     * }
     */
    public function decideOpen(string $symbolCode): array
    {
        $cfg = $this->settings->get();
        $timeframes = $cfg['timeframes'];
        $weights = $cfg['weights'];
        $threshold = (int) $cfg['total_threshold'];

        $dirs = [];
        $total = 0;

        foreach ($timeframes as $tf) {
            // IMPORTANT: use LAST CLOSED candle for stability (no unclosed candle noise)
            $dir = $this->haDirFromLastClosedCandle($symbolCode, (string) $tf);

            if ($dir === null) {
                return [
                    'action' => 'hold',
                    'reason' => 'not_enough_candles',
                    'debug' => ['missing_tf' => $tf],
                ];
            }

            $dirs[$tf] = $dir;

            $sign = $dir === 'up' ? 1 : ($dir === 'down' ? -1 : 0);
            $w = (int) ($weights[$tf] ?? 0);
            $total += ($sign * $w);
        }

        if (abs($total) < $threshold) {
            return [
                'action' => 'hold',
                'reason' => 'no_edge',
                'debug' => [
                    'vote_total' => $total,
                    'threshold' => $threshold,
                    'dirs' => $dirs,
                ],
            ];
        }

        $side = $total > 0 ? 'buy' : 'sell';
        $wantedDir = $side === 'buy' ? 'up' : 'down';

        // Candidates are CURRENT TF + ENTRY (lower) TF pairs:
        // Entry is on the LOWER TF, but it must be confirmed by CURRENT TF,
        // and CURRENT TF must have at least one SENIOR TF also in wantedDir.
        $candidates = $this->entryCandidates($wantedDir, $dirs);

        if ($candidates === []) {
            return [
                'action' => 'hold',
                'reason' => 'no_entry_timeframe',
                'debug' => [
                    'vote_total' => $total,
                    'dirs' => $dirs,
                ],
            ];
        }

        $entryTf = null;
        $currentTf = null;

        $flatDebug = [];

        foreach ($candidates as $pair) {
            $curr = (string) $pair['current'];
            $entry = (string) $pair['entry'];

            // Flat filter applies to ENTRY TF (the actual execution timeframe)
            $isFlat = $this->isFlat(
                symbolCode: $symbolCode,
                tf: $entry,
                lookback: (int) $cfg['flat']['lookback_candles'],
                threshold: (float) $cfg['flat']['range_pct_threshold'],
                flatDebug: $flatDebug
            );

            if (!$isFlat) {
                $entryTf = $entry;
                $currentTf = $curr;
                break;
            }
        }

        if ($entryTf === null || $currentTf === null) {
            return [
                'action' => 'hold',
                'reason' => 'all_candidates_flat',
                'debug' => [
                    'vote_total' => $total,
                    'dirs' => $dirs,
                    'candidates' => $candidates,
                    'flat' => $flatDebug,
                ],
            ];
        }

        // Recompute entry direction for safety (LAST CLOSED candle)
        $entryDir = $this->haDirFromLastClosedCandle($symbolCode, $entryTf);
        if ($entryDir === null) {
            return [
                'action' => 'hold',
                'reason' => 'not_enough_candles',
                'debug' => ['entry_tf' => $entryTf],
            ];
        }

        if ($entryDir !== $wantedDir) {
            return [
                'action' => 'hold',
                'reason' => 'entry_candle_not_in_direction',
                'debug' => [
                    'vote_total' => $total,
                    'side' => $side,
                    'wanted_dir' => $wantedDir,
                    'current_tf' => $currentTf,
                    'entry_tf' => $entryTf,
                    'entry_dir_from_dirs' => $dirs[$entryTf] ?? null,
                    'entry_dir_recomputed' => $entryDir,
                    'dirs' => $dirs,
                    'candidates' => $candidates,
                ],
            ];
        }

        return [
            'action' => 'open',
            'reason' => 'strategy_entry',
            'side' => $side,
            'timeframe_code' => $entryTf,
            'debug' => [
                'vote_total' => $total,
                'dirs' => $dirs,
                'candidates' => $candidates,
                'flat' => $flatDebug,
                'current_tf' => $currentTf,
                'entry_tf' => $entryTf,
                'entry_dir' => $entryDir,
            ],
        ];
    }

    /**
     * @return array{
     *   action: 'arm_stop'|'hold',
     *   reason: string,
     *   stop_price?: float,
     *   tf_entry?: string,
     *   tf_lower?: string,
     *   debug?: array<string, mixed>
     * }
     */
    public function decideArmExitStop(string $side, string $symbolCode, string $tfEntry): array
    {
        $tfLower = $this->lowerTimeframe($tfEntry);
        if ($tfLower === null) {
            return [
                'action' => 'hold',
                'reason' => 'no_lower_timeframe',
                'debug' => ['tf_entry' => $tfEntry],
            ];
        }

        $wantedEntryDir = $side === 'buy' ? 'up' : 'down';
        $wantedLowerDir = $side === 'buy' ? 'down' : 'up';

        // IMPORTANT: use LAST CLOSED candle for stability
        $entryCurrDir = $this->haDirFromLastClosedCandle($symbolCode, $tfEntry);
        $lowerCurrDir = $this->haDirFromLastClosedCandle($symbolCode, $tfLower);

        if ($entryCurrDir === null || $lowerCurrDir === null) {
            return [
                'action' => 'hold',
                'reason' => 'not_enough_candles',
                'debug' => ['tf_entry' => $tfEntry, 'tf_lower' => $tfLower],
            ];
        }

        if ($entryCurrDir !== $wantedEntryDir) {
            return [
                'action' => 'hold',
                'reason' => 'entry_not_ok',
                'debug' => [
                    'tf_entry' => $tfEntry,
                    'entry_curr_dir' => $entryCurrDir,
                ],
            ];
        }

        if ($lowerCurrDir !== $wantedLowerDir) {
            return [
                'action' => 'hold',
                'reason' => 'lower_not_against',
                'debug' => [
                    'tf_entry' => $tfEntry,
                    'tf_lower' => $tfLower,
                    'lower_curr_dir' => $lowerCurrDir,
                ],
            ];
        }

        // Previous CLOSED candle on entry TF (still ok; we need its low/high as a level)
        $prev = Candle::query()
                      ->where('symbol_code', $symbolCode)
                      ->where('timeframe_code', $tfEntry)
                      ->orderByDesc('open_time_ms')
                      ->skip(2) // skip current (maybe unclosed) + last closed, take previous closed
                      ->first();

        if (!$prev) {
            return [
                'action' => 'hold',
                'reason' => 'not_enough_candles_for_stop',
                'debug' => ['tf_entry' => $tfEntry],
            ];
        }

        $stopPrice = $side === 'buy'
            ? (float) $prev->low
            : (float) $prev->high;

        return [
            'action' => 'arm_stop',
            'reason' => 'lower_tf_first_against',
            'stop_price' => $stopPrice,
            'tf_entry' => $tfEntry,
            'tf_lower' => $tfLower,
            'debug' => [
                'entry_curr_dir' => $entryCurrDir,
                'lower_curr_dir' => $lowerCurrDir,
            ],
        ];
    }

    /**
     * Build entry candidates as pairs:
     * - 'current' is the confirming timeframe (must be in wantedDir)
     * - 'entry' is the lower timeframe where we actually open (must be in wantedDir)
     * - current must have at least one senior TF also in wantedDir
     * - exclude current=1d (no seniors) and entry=5m (do not trade on 5m)
     *
     * @return array<int, array{current: string, entry: string}>
     */
    private function entryCandidates(string $wantedDir, array $dirs): array
    {
        // Strict ladder pairs (current -> lower(entry))
        $pairs = [
            ['1d', '4h'],
            ['4h', '1h'],
            ['1h', '30m'],
            ['30m', '15m'],
            ['15m', '5m'],
        ];

        $out = [];

        foreach ($pairs as [$current, $entry]) {
            // exclude trading on 1d as current (no seniors to confirm)
            if ($current === TimeframeCode::D1->value) {
                continue;
            }

            // exclude trading on 5m as entry (no lower TF for exit logic)
            if ($entry === TimeframeCode::M5->value) {
                continue;
            }

            if (($dirs[$current] ?? null) !== $wantedDir) {
                continue;
            }

            if (($dirs[$entry] ?? null) !== $wantedDir) {
                continue;
            }

            if (!$this->hasAnySeniorInDir($current, $wantedDir, $dirs)) {
                continue;
            }

            $out[] = ['current' => $current, 'entry' => $entry];
        }

        return $out;
    }

    private function hasAnySeniorInDir(string $currentTf, string $wantedDir, array $dirs): bool
    {
        $order = [
            TimeframeCode::M5->value => 0,
            TimeframeCode::M15->value => 1,
            TimeframeCode::M30->value => 2,
            TimeframeCode::H1->value => 3,
            TimeframeCode::H4->value => 4,
            TimeframeCode::D1->value => 5,
        ];

        $idx = $order[$currentTf] ?? null;
        if ($idx === null) {
            return false;
        }

        foreach ($order as $tf => $i) {
            if ($i > $idx && (($dirs[$tf] ?? null) === $wantedDir)) {
                return true;
            }
        }

        return false;
    }

    private function isFlat(
        string $symbolCode,
        string $tf,
        int $lookback,
        float $threshold,
        array &$flatDebug
    ): bool {
        $candles = Candle::query()
                         ->where('symbol_code', $symbolCode)
                         ->where('timeframe_code', $tf)
                         ->orderByDesc('open_time_ms')
                         ->limit($lookback)
                         ->get();

        if ($candles->count() < $lookback) {
            $flatDebug[$tf] = ['ok' => false, 'reason' => 'not_enough_candles'];
            return true;
        }

        $high = $candles->max(fn (Candle $c) => (float) $c->high);
        $low = $candles->min(fn (Candle $c) => (float) $c->low);
        $lastClose = (float) $candles->first()->close;

        if ($lastClose <= 0) {
            $flatDebug[$tf] = ['ok' => false, 'reason' => 'bad_last_close'];
            return true;
        }

        $rangePct = ($high - $low) / $lastClose;

        $flatDebug[$tf] = [
            'ok' => true,
            'range_pct' => $rangePct,
            'threshold' => $threshold,
        ];

        return $rangePct < $threshold;
    }

    /**
     * HA direction for LAST CLOSED candle using minimal classic recursion (prev candle).
     * We compute HA_Close for both candles, and HA_Open for the current closed candle from prev HA values:
     *   HA_Open[t] = (HA_Open[t-1] + HA_Close[t-1]) / 2
     * For seeding HA_Open[t-1] we use (O+C)/2 for prev candle (minimal, stable, no history scan).
     */
    private function haDirFromLastClosedCandle(string $symbolCode, string $tf): ?string
    {
        $candles = Candle::query()
                         ->where('symbol_code', $symbolCode)
                         ->where('timeframe_code', $tf)
                         ->orderByDesc('open_time_ms')
                         ->skip(1)   // skip current (possibly unclosed)
                         ->limit(2)  // take 2 closed: latest closed + previous closed
                         ->get();

        if ($candles->count() < 2) {
            return null;
        }

        /** @var Candle $currClosed */
        $currClosed = $candles[0];
        /** @var Candle $prevClosed */
        $prevClosed = $candles[1];

        return $this->haDirForCandleWithPrev($currClosed, $prevClosed);
    }

    private function haDirForCandleWithPrev(Candle $curr, Candle $prev): string
    {
        $prevHaClose = $this->haCloseFromOhlc($prev);
        $prevHaOpenSeed = $this->haOpenSeedFromOhlc($prev);

        $haOpen = ($prevHaOpenSeed + $prevHaClose) / 2.0;
        $haClose = $this->haCloseFromOhlc($curr);

        return $this->haDirection($haOpen, $haClose);
    }

    private function haCloseFromOhlc(Candle $c): float
    {
        return ((float) $c->open + (float) $c->high + (float) $c->low + (float) $c->close) / 4.0;
    }

    private function haOpenSeedFromOhlc(Candle $c): float
    {
        return ((float) $c->open + (float) $c->close) / 2.0;
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
