<?php

namespace App\Services\Trading;

use App\Contracts\StrategySettingsRepository;
use App\Enums\TimeframeCode;
use App\Services\Trading\PriceWindowService;

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
        $dirFlatThresholdPct = (float) $cfg['price_windows']['dir_flat_threshold_pct'];
        $tfConfigs = $cfg['price_windows']['timeframes'];

        $dirs = [];
        $windows = [];
        $insufficientTicks = [];
        $total = 0;

        foreach ($timeframes as $tf) {
            $tfConfig = $tfConfigs[$tf] ?? null;
            if (!$tfConfig) {
                return [
                    'action' => 'hold',
                    'reason' => 'missing_timeframe_config',
                    'debug' => ['missing_tf' => $tf],
                ];
            }

            $window = (new PriceWindowService())->window(
                symbolCode: $symbolCode,
                minutes: $tfConfig['minutes'],
                points: $tfConfig['points'],
                dirFlatThresholdPct: $dirFlatThresholdPct
            );

            $dir = match ($window['dir'] ?? null) {
                'up' => 'up',
                'down' => 'down',
                default => 'flat',
            };

            $dirs[$tf] = $dir;
            $windows[$tf] = $window;

            // Track insufficient ticks for debug
            if (($window['current']['count'] ?? 0) < $tfConfig['points']) {
                $insufficientTicks[] = $tf;
            }

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
                    'windows' => $windows,
                    'insufficient_ticks' => $insufficientTicks,
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
                    'windows' => $windows,
                    'insufficient_ticks' => $insufficientTicks,
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
            $isFlat = $this->isFlatFromWindow(
                symbolCode: $symbolCode,
                tf: $entry,
                tfConfigs: $tfConfigs,
                dirFlatThresholdPct: $dirFlatThresholdPct,
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
                    'windows' => $windows,
                    'insufficient_ticks' => $insufficientTicks,
                ],
            ];
        }

        // Recompute entry direction for safety (window)
        $entryWindow = (new PriceWindowService())->window(
            symbolCode: $symbolCode,
            minutes: $tfConfigs[$entryTf]['minutes'],
            points: $tfConfigs[$entryTf]['points'],
            dirFlatThresholdPct: $dirFlatThresholdPct
        );

        $entryDir = match ($entryWindow['dir'] ?? null) {
            'up' => 'up',
            'down' => 'down',
            default => 'flat',
        };

        if ($entryDir !== $wantedDir) {
            return [
                'action' => 'hold',
                'reason' => 'entry_not_in_direction',
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
                    'windows' => $windows,
                    'insufficient_ticks' => $insufficientTicks,
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
                'windows' => $windows,
                'insufficient_ticks' => $insufficientTicks,
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
        return [
            'action' => 'hold',
            'reason' => 'price_only_mode',
            'debug' => ['tf_entry' => $tfEntry],
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

    private function isFlatFromWindow(
        string $symbolCode,
        string $tf,
        array $tfConfigs,
        float $dirFlatThresholdPct,
        array &$flatDebug
    ): bool {
        $tfConfig = $tfConfigs[$tf] ?? null;
        if (!$tfConfig) {
            $flatDebug[$tf] = ['ok' => false, 'reason' => 'missing_tf_config'];
            return true;
        }

        $window = (new PriceWindowService())->window(
            symbolCode: $symbolCode,
            minutes: $tfConfig['minutes'],
            points: $tfConfig['points'],
            dirFlatThresholdPct: $dirFlatThresholdPct
        );

        $current = $window['current'] ?? [];
        $avg = $current['avg'] ?? null;
        $range = $current['range'] ?? null;
        $count = $current['count'] ?? 0;
        $points = $tfConfig['points'];

        if ($avg === null || $range === null || $count < $points) {
            $flatDebug[$tf] = [
                'ok' => false,
                'reason' => 'insufficient_data',
                'avg' => $avg,
                'range' => $range,
                'count' => $count,
                'required_points' => $points,
            ];
            return true;
        }

        $rangePct = $range / $avg;
        $threshold = (float) $this->settings->get()['flat']['range_pct_threshold'];

        $flatDebug[$tf] = [
            'ok' => true,
            'range_pct' => $rangePct,
            'threshold' => $threshold,
            'avg' => $avg,
            'range' => $range,
            'count' => $count,
        ];

        return $rangePct < $threshold;
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
}
