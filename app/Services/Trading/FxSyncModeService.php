<?php

namespace App\Services\Trading;

use App\Models\Symbol;
use App\Models\Trade;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class FxSyncModeService
{
    private FxSessionScheduler $scheduler;

    public function __construct()
    {
        $this->scheduler = app(FxSessionScheduler::class);
    }

    public function getModeCards(CarbonInterface $now): array
    {
        $now = Carbon::instance($now);

        // Fetch active symbols once
        $activeSymbols = Symbol::query()
            ->where('is_active', true)
            ->pluck('code')
            ->toArray();

        // Preload open-trade existence per symbol with one query
        $openTradesBySymbol = Trade::query()
            ->where('status', 'open')
            ->whereIn('symbol_code', $activeSymbols)
            ->pluck('symbol_code')
            ->flip()
            ->toArray();

        $cards = [];

        foreach ($activeSymbols as $symbolCode) {
            $hasOpenTrade = isset($openTradesBySymbol[$symbolCode]);

            // Get active sessions for this symbol
            $mappedSessions = $this->scheduler->mappedSessions($symbolCode);
            $activeSessions = [];

            foreach ($mappedSessions as $sessionName) {
                if ($this->scheduler->isSessionActive($sessionName, $now)) {
                    $activeSessions[] = $sessionName;
                }
            }

            // Determine mode and reason based on active sessions and open trades
            if (!empty($activeSessions)) {
                $mode = 'FAST';
                $reason = 'session';
            } elseif ($hasOpenTrade) {
                $mode = 'FAST';
                $reason = 'open_trade';
            } else {
                $mode = 'SLOW';
                $reason = 'idle';
            }

            $intervalMinutes = $this->scheduler->syncIntervalMinutes($symbolCode, $now, $hasOpenTrade);
            $nextMode = $mode === 'FAST' ? 'SLOW' : 'FAST';
            $modeChangesInSeconds = $this->computeModeChangesInSeconds($symbolCode, $now, $mode, $hasOpenTrade);

            $cards[] = [
                'symbol_code' => $symbolCode,
                'mode' => $mode,
                'interval_minutes' => $intervalMinutes,
                'reason' => $reason,
                'active_sessions' => $activeSessions,
                'next_mode' => $nextMode,
                'mode_changes_in_seconds' => $modeChangesInSeconds,
            ];
        }

        return $cards;
    }

    private function computeModeChangesInSeconds(string $symbolCode, CarbonInterface $now, string $currentMode, bool $hasOpenTrade): ?int
    {
        $mappedSessions = $this->scheduler->mappedSessions($symbolCode);

        if ($currentMode === 'SLOW') {
            // Find the earliest upcoming session window start (including warmup) in the future
            $earliestStart = null;

            foreach ($mappedSessions as $sessionName) {
                $window = $this->scheduler->sessionWindowForNow($sessionName, $now);
                $start = $window['window_start'];

                if ($start->gt($now)) {
                    if ($earliestStart === null || $start->lt($earliestStart)) {
                        $earliestStart = $start;
                    }
                }
            }

            if ($earliestStart !== null) {
                return $now->diffInSeconds($earliestStart, false);
            }

            // If no upcoming session found, return null (no change expected)
            return null;
        }

        // Current mode is FAST
        if ($this->scheduler->isInTradingWindow($symbolCode, $now)) {
            // FAST due to session: find the latest active session window END (including cooldown)
            $latestEnd = null;

            foreach ($mappedSessions as $sessionName) {
                if ($this->scheduler->isSessionActive($sessionName, $now)) {
                    $window = $this->scheduler->sessionWindowForNow($sessionName, $now);
                    $end = $window['window_end'];

                    if ($latestEnd === null || $end->gt($latestEnd)) {
                        $latestEnd = $end;
                    }
                }
            }

            if ($latestEnd !== null) {
                return $now->diffInSeconds($latestEnd, false);
            }

            // If no active session found, return null
            return null;
        }

        // FAST due to open_trade but NO active sessions
        // Mode change is null (after trade closes)
        return null;
    }
}
