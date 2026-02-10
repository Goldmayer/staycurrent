<?php

namespace App\Services\Trading;

use App\Models\SymbolQuote;
use App\Models\Trade;

class TradeExitDecisionService
{
    public function __construct(
        private readonly PriceWindowService $priceWindowService,
    ) {}

    /**
     * @return array{action:string, reason:string, debug:array}
     */
    public function decideClose(Trade $trade): array
    {
        $cfg = config('trading.exit', []);

        $quote = SymbolQuote::query()->where('symbol_code', $trade->symbol_code)->first();
        if (!$quote?->price) {
            return [
                'action' => 'hold',
                'reason' => 'missing_quote',
                'debug' => [],
            ];
        }

        $now = now();
        $maxAgeSec = (int) ($cfg['quote_max_age_seconds'] ?? 120);
        $pulledAt = $quote->pulled_at ? $quote->pulled_at->copy() : null;

        if ($pulledAt && $pulledAt->diffInSeconds($now) > $maxAgeSec) {
            return [
                'action' => 'hold',
                'reason' => 'stale_quote',
                'debug' => ['pulled_at' => (string) $pulledAt],
            ];
        }

        $price = (float) $quote->price;
        $entry = (float) $trade->entry_price;

        // --- Update best_price in meta (no migration) ---
        $meta = is_array($trade->meta) ? $trade->meta : [];
        $best = isset($meta['best_price']) ? (float) $meta['best_price'] : null;

        if ($best === null) {
            $best = $entry;
            $meta['best_price'] = $best;
            $meta['best_price_at'] = $now->toIso8601String();
        } else {
            if ($trade->isLong() && $price > $best) {
                $best = $price;
                $meta['best_price'] = $best;
                $meta['best_price_at'] = $now->toIso8601String();
            }
            if ($trade->isShort() && $price < $best) {
                $best = $price;
                $meta['best_price'] = $best;
                $meta['best_price_at'] = $now->toIso8601String();
            }
        }

        // Persist meta update silently (only if changed)
        if ($meta !== (is_array($trade->meta) ? $trade->meta : [])) {
            $trade->meta = $meta;
            $trade->save();
        }

        // --- 1) Time stop ---
        $maxHold = (int) ($trade->max_hold_minutes ?? 0);
        if ($maxHold > 0 && $trade->opened_at) {
            $ageMin = $trade->opened_at->diffInMinutes($now);
            if ($ageMin >= $maxHold) {
                return [
                    'action' => 'close',
                    'reason' => 'time_stop',
                    'debug' => ['age_min' => $ageMin, 'max_hold' => $maxHold],
                ];
            }
        }

        // --- 2) Hard stop by pct ---
        $hardStopPct = (float) ($cfg['hard_stop_pct'] ?? 0.0020); // 0.20%
        if ($hardStopPct > 0) {
            if ($trade->isLong()) {
                $stop = $entry * (1.0 - $hardStopPct);
                if ($price <= $stop) {
                    return [
                        'action' => 'close',
                        'reason' => 'hard_stop',
                        'debug' => ['entry' => $entry, 'price' => $price, 'stop' => $stop],
                    ];
                }
            } else {
                $stop = $entry * (1.0 + $hardStopPct);
                if ($price >= $stop) {
                    return [
                        'action' => 'close',
                        'reason' => 'hard_stop',
                        'debug' => ['entry' => $entry, 'price' => $price, 'stop' => $stop],
                    ];
                }
            }
        }

        // --- Compute windows for exit timeframes ---
        $tfs = $cfg['tfs'] ?? ['5m', '15m', '30m'];
        $windows = [];
        $against = 0;
        $againstList = [];

        $minStrength = (float) ($cfg['reversal_min_strength_pct'] ?? 0.00015);
        $minAgainst = (int) ($cfg['reversal_min_against_count'] ?? 2);

        foreach ($tfs as $tf) {
            $w = $this->priceWindowService->build($trade->symbol_code, $tf);
            $windows[$tf] = $w;

            $dir = $w['dir'] ?? 'no_data';
            $dirPct = isset($w['dir_pct']) ? (float) $w['dir_pct'] : null;

            $isAgainst = false;
            if ($trade->isLong()) {
                $isAgainst = ($dir === 'down');
            } else {
                $isAgainst = ($dir === 'up');
            }

            if ($isAgainst && $dirPct !== null && abs($dirPct) >= $minStrength) {
                $against++;
                $againstList[] = ['tf' => $tf, 'dir' => $dir, 'dir_pct' => $dirPct];
            }
        }

        // --- 3) Reversal exit ---
        if ($against >= $minAgainst) {
            return [
                'action' => 'close',
                'reason' => 'reversal',
                'debug' => [
                    'against' => $against,
                    'min_against' => $minAgainst,
                    'min_strength' => $minStrength,
                    'against_list' => $againstList,
                    'windows' => $windows,
                ],
            ];
        }

        // --- 4) Trailing exit (drawdown from best_price) ---
        $trailPct = (float) ($cfg['trailing_pct'] ?? 0.0015); // 0.15%
        $minProfitToTrail = (float) ($cfg['min_profit_to_trail_pct'] ?? 0.0005); // 0.05%

        if ($trailPct > 0 && $best !== null) {
            $profitPct = $trade->isLong()
                ? (($price - $entry) / $entry)
                : (($entry - $price) / $entry);

            if ($profitPct >= $minProfitToTrail) {
                $drawdownPct = $trade->isLong()
                    ? (($best - $price) / $best)
                    : (($price - $best) / $best);

                if ($drawdownPct >= $trailPct) {
                    return [
                        'action' => 'close',
                        'reason' => 'trailing_stop',
                        'debug' => [
                            'entry' => $entry,
                            'price' => $price,
                            'best' => $best,
                            'profit_pct' => $profitPct,
                            'drawdown_pct' => $drawdownPct,
                            'trail_pct' => $trailPct,
                        ],
                    ];
                }
            }
        }

        return [
            'action' => 'hold',
            'reason' => 'no_exit_signal',
            'debug' => [
                'entry' => $entry,
                'price' => $price,
                'best' => $best,
                'windows' => $windows,
            ],
        ];
    }
}
