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

        $timeframes = (array) ($cfg['timeframes'] ?? []);
        $weights = (array) ($cfg['weights'] ?? []);
        $threshold = (int) ($cfg['total_threshold'] ?? 0);

        $priceWindows = $cfg['price_windows'] ?? config('trading.strategy.price_windows', []);
        $dirFlatThresholdPct = (float) ($priceWindows['dir_flat_threshold_pct'] ?? 0.0001);
        $tfConfigs = (array) ($priceWindows['timeframes'] ?? []);

        $flatRangePctThreshold = (float) (
            $cfg['flat']['range_pct_threshold']
            ?? config('trading.strategy.flat.range_pct_threshold', 0.002)
        );

        $entryConfirm = (array) ($cfg['entry_confirm'] ?? config('trading.strategy.entry_confirm', []));
        $allowedEntryTfs = (array) ($entryConfirm['allowed_entry_tfs'] ?? ['15m', '30m', '1h', '4h']);
        $minSeniorsByTf = (array) ($entryConfirm['min_seniors_in_dir'] ?? []);
        $requireLowerTfConfirmation = (bool) ($entryConfirm['require_lower_tf_confirmation'] ?? true);

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

            if (($window['current']['count'] ?? 0) < (int) $tfConfig['points']) {
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

        // Evaluate entry candidates by rules:
        // entry TF is the execution TF (15m..4h)
        // - entry must be in wantedDir
        // - at least N seniors of entry must be in wantedDir (N from config per entry TF)
        // - optionally require immediate LOWER TF confirmation (lower must be in wantedDir)
        // - flat filter applies to entry TF
        [$entryDecision, $entryDebug] = $this->pickEntryTimeframe(
            symbolCode: $symbolCode,
            wantedDir: $wantedDir,
            dirs: $dirs,
            tfConfigs: $tfConfigs,
            dirFlatThresholdPct: $dirFlatThresholdPct,
            flatRangePctThreshold: $flatRangePctThreshold,
            allowedEntryTfs: $allowedEntryTfs,
            minSeniorsByTf: $minSeniorsByTf,
            requireLowerTfConfirmation: $requireLowerTfConfirmation
        );

        if ($entryDecision['action'] === 'open') {
            return [
                'action' => 'open',
                'reason' => 'strategy_entry',
                'side' => $side,
                'timeframe_code' => $entryDecision['timeframe_code'],
                'debug' => array_merge([
                    'vote_total' => $total,
                    'dirs' => $dirs,
                    'windows' => $windows,
                    'insufficient_ticks' => $insufficientTicks,
                    'wanted_dir' => $wantedDir,
                ], $entryDebug),
            ];
        }

        // waiting_lower_reversal has priority over no_entry_timeframe (more informative)
        if (($entryDecision['reason'] ?? null) === 'waiting_lower_reversal') {
            return [
                'action' => 'hold',
                'reason' => 'waiting_lower_reversal',
                'debug' => array_merge([
                    'vote_total' => $total,
                    'dirs' => $dirs,
                    'windows' => $windows,
                    'insufficient_ticks' => $insufficientTicks,
                    'wanted_dir' => $wantedDir,
                ], $entryDebug),
            ];
        }

        // all_candidates_flat has priority over no_entry_timeframe (more informative)
        if (($entryDecision['reason'] ?? null) === 'all_candidates_flat') {
            return [
                'action' => 'hold',
                'reason' => 'all_candidates_flat',
                'debug' => array_merge([
                    'vote_total' => $total,
                    'dirs' => $dirs,
                    'windows' => $windows,
                    'insufficient_ticks' => $insufficientTicks,
                    'wanted_dir' => $wantedDir,
                ], $entryDebug),
            ];
        }

        return [
            'action' => 'hold',
            'reason' => 'no_entry_timeframe',
            'debug' => array_merge([
                'vote_total' => $total,
                'dirs' => $dirs,
                'windows' => $windows,
                'insufficient_ticks' => $insufficientTicks,
                'wanted_dir' => $wantedDir,
            ], $entryDebug),
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
     * Pick entry timeframe according to new rules.
     *
     * Returns:
     * - ['action' => 'open', 'timeframe_code' => '...'] on success
     * - ['action' => 'hold', 'reason' => 'waiting_lower_reversal'|'all_candidates_flat'|'no_entry_timeframe']
     *
     * Debug contains:
     * - ready_entries (candidates that satisfy everything incl lower dir)
     * - waiting_entries (candidates that satisfy seniors+entry dir but lower not yet in dir)
     * - rejected_entries (other failures)
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function pickEntryTimeframe(
        string $symbolCode,
        string $wantedDir,
        array $dirs,
        array $tfConfigs,
        float $dirFlatThresholdPct,
        float $flatRangePctThreshold,
        array $allowedEntryTfs,
        array $minSeniorsByTf,
        bool $requireLowerTfConfirmation
    ): array {
        // Prefer more senior entries first to reduce churn (4h -> 1h -> 30m -> 15m)
        $allowedEntryTfs = $this->sortEntryTfsSeniorFirst($allowedEntryTfs);

        $ready = [];
        $waiting = [];
        $rejected = [];
        $flatDebug = [];

        foreach ($allowedEntryTfs as $entryTf) {
            $entryTf = (string) $entryTf;

            // enforce allowed range M15..H4
            if (!in_array($entryTf, [
                TimeframeCode::M15->value,
                TimeframeCode::M30->value,
                TimeframeCode::H1->value,
                TimeframeCode::H4->value,
            ], true)) {
                $rejected[] = [
                    'entry_tf' => $entryTf,
                    'reason' => 'entry_tf_not_allowed_range',
                ];
                continue;
            }

            $entryDir = $dirs[$entryTf] ?? null;
            if ($entryDir !== $wantedDir) {
                $rejected[] = [
                    'entry_tf' => $entryTf,
                    'reason' => 'entry_not_in_direction',
                    'entry_dir' => $entryDir,
                    'wanted_dir' => $wantedDir,
                ];
                continue;
            }

            $requiredSeniors = (int) ($minSeniorsByTf[$entryTf] ?? 1);
            $seniorCount = $this->countSeniorsInDir($entryTf, $wantedDir, $dirs);

            if ($seniorCount < $requiredSeniors) {
                $rejected[] = [
                    'entry_tf' => $entryTf,
                    'reason' => 'insufficient_senior_confirmation',
                    'seniors_in_dir_count' => $seniorCount,
                    'required_seniors' => $requiredSeniors,
                ];
                continue;
            }

            $lowerTf = $this->lowerTimeframe($entryTf);
            if ($requireLowerTfConfirmation) {
                if ($lowerTf === null) {
                    $rejected[] = [
                        'entry_tf' => $entryTf,
                        'reason' => 'missing_lower_timeframe',
                    ];
                    continue;
                }

                $lowerDir = $dirs[$lowerTf] ?? null;
                if ($lowerDir !== $wantedDir) {
                    $waiting[] = [
                        'entry_tf' => $entryTf,
                        'lower_tf' => $lowerTf,
                        'lower_dir_now' => $lowerDir,
                        'wanted_dir' => $wantedDir,
                        'seniors_in_dir_count' => $seniorCount,
                        'required_seniors' => $requiredSeniors,
                    ];
                    continue;
                }
            }

            $isFlat = $this->isFlatFromWindow(
                symbolCode: $symbolCode,
                tf: $entryTf,
                tfConfigs: $tfConfigs,
                dirFlatThresholdPct: $dirFlatThresholdPct,
                flatRangePctThreshold: $flatRangePctThreshold,
                flatDebug: $flatDebug
            );

            if ($isFlat) {
                $rejected[] = [
                    'entry_tf' => $entryTf,
                    'reason' => 'entry_tf_flat',
                    'flat' => $flatDebug[$entryTf] ?? null,
                ];
                continue;
            }

            $ready[] = [
                'entry_tf' => $entryTf,
                'lower_tf' => $lowerTf,
                'seniors_in_dir_count' => $seniorCount,
                'required_seniors' => $requiredSeniors,
            ];

            return [
                ['action' => 'open', 'timeframe_code' => $entryTf],
                [
                    'entry_tf' => $entryTf,
                    'lower_tf' => $lowerTf,
                    'flat' => $flatDebug,
                    'ready_entries' => $ready,
                    'waiting_entries' => $waiting,
                    'rejected_entries' => $rejected,
                ],
            ];
        }

        if ($ready === [] && $waiting !== []) {
            return [
                ['action' => 'hold', 'reason' => 'waiting_lower_reversal'],
                [
                    'flat' => $flatDebug,
                    'ready_entries' => $ready,
                    'waiting_entries' => $waiting,
                    'rejected_entries' => $rejected,
                ],
            ];
        }

        // If there were candidates but all got filtered by flat, show that reason
        $hadEntryDirCandidates = $this->hadAnyEntryDirCandidates($allowedEntryTfs, $wantedDir, $dirs);
        if ($hadEntryDirCandidates && $waiting === [] && $ready === []) {
            $hadOnlyFlatRejections = $this->hadOnlyFlatRejections($rejected);
            if ($hadOnlyFlatRejections) {
                return [
                    ['action' => 'hold', 'reason' => 'all_candidates_flat'],
                    [
                        'flat' => $flatDebug,
                        'ready_entries' => $ready,
                        'waiting_entries' => $waiting,
                        'rejected_entries' => $rejected,
                    ],
                ];
            }
        }

        return [
            ['action' => 'hold', 'reason' => 'no_entry_timeframe'],
            [
                'flat' => $flatDebug,
                'ready_entries' => $ready,
                'waiting_entries' => $waiting,
                'rejected_entries' => $rejected,
            ],
        ];
    }

    private function hadAnyEntryDirCandidates(array $allowedEntryTfs, string $wantedDir, array $dirs): bool
    {
        foreach ($allowedEntryTfs as $tf) {
            $tf = (string) $tf;
            if (($dirs[$tf] ?? null) === $wantedDir) {
                return true;
            }
        }
        return false;
    }

    private function hadOnlyFlatRejections(array $rejected): bool
    {
        if ($rejected === []) {
            return false;
        }
        foreach ($rejected as $r) {
            if (($r['reason'] ?? null) !== 'entry_tf_flat') {
                return false;
            }
        }
        return true;
    }

    private function sortEntryTfsSeniorFirst(array $tfs): array
    {
        $order = [
            TimeframeCode::M15->value => 0,
            TimeframeCode::M30->value => 1,
            TimeframeCode::H1->value => 2,
            TimeframeCode::H4->value => 3,
        ];

        usort($tfs, function ($a, $b) use ($order) {
            $ai = $order[(string) $a] ?? -1;
            $bi = $order[(string) $b] ?? -1;
            return $bi <=> $ai; // senior first
        });

        return array_values($tfs);
    }

    private function countSeniorsInDir(string $entryTf, string $wantedDir, array $dirs): int
    {
        $order = [
            TimeframeCode::M5->value => 0,
            TimeframeCode::M15->value => 1,
            TimeframeCode::M30->value => 2,
            TimeframeCode::H1->value => 3,
            TimeframeCode::H4->value => 4,
            TimeframeCode::D1->value => 5,
        ];

        $idx = $order[$entryTf] ?? null;
        if ($idx === null) {
            return 0;
        }

        $cnt = 0;
        foreach ($order as $tf => $i) {
            if ($i > $idx && (($dirs[$tf] ?? null) === $wantedDir)) {
                $cnt++;
            }
        }

        return $cnt;
    }

    private function isFlatFromWindow(
        string $symbolCode,
        string $tf,
        array $tfConfigs,
        float $dirFlatThresholdPct,
        float $flatRangePctThreshold,
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
        $points = (int) ($tfConfig['points'] ?? 0);

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

        $flatDebug[$tf] = [
            'ok' => true,
            'range_pct' => $rangePct,
            'threshold' => $flatRangePctThreshold,
            'avg' => $avg,
            'range' => $range,
            'count' => $count,
        ];

        return $rangePct < $flatRangePctThreshold;
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
