<?php

namespace App\Services\Trading;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class FxSessionScheduler
{
    private array $config;

    public function __construct()
    {
        $this->config = config('trading.fx_sessions', [
            'timezone' => 'UTC',
            'fast_interval_minutes' => 1,
            'slow_interval_minutes' => 30,
            'sessions' => [
                'sydney' => [
                    'start' => '22:00',
                    'end' => '07:00',
                    'warmup_minutes' => 60,
                    'cooldown_minutes' => 120,
                ],
                'tokyo' => [
                    'start' => '00:00',
                    'end' => '09:00',
                    'warmup_minutes' => 30,
                    'cooldown_minutes' => 60,
                ],
                'london' => [
                    'start' => '08:00',
                    'end' => '17:00',
                    'warmup_minutes' => 60,
                    'cooldown_minutes' => 120,
                ],
                'newyork' => [
                    'start' => '13:00',
                    'end' => '22:00',
                    'warmup_minutes' => 60,
                    'cooldown_minutes' => 120,
                ],
            ],
            'currencies' => [
                'JPY' => ['tokyo'],
                'EUR' => ['london'],
                'GBP' => ['london'],
                'USD' => ['newyork'],
                'CAD' => ['newyork'],
                'AUD' => ['sydney'],
                'NZD' => ['sydney'],
                'CHF' => ['london'],
            ],
        ]);
    }

    public function isInTradingWindow(string $symbolCode, CarbonInterface $now): bool
    {
        $now = Carbon::instance($now)->setTimezone($this->config['timezone']);

        // Weekend rule: no new entries on Saturday or Sunday (config timezone, default UTC)
        if ($now->isWeekend()) {
            return false;
        }

        $mappedSessions = $this->getMappedSessions($symbolCode);

        foreach ($mappedSessions as $sessionName) {
            if ($this->isSessionActive($sessionName, $now)) {
                return true;
            }
        }

        return false;
    }

    public function syncIntervalMinutes(string $symbolCode, CarbonInterface $now, bool $hasOpenTrade): int
    {
        if ($hasOpenTrade) {
            return (int) $this->config['fast_interval_minutes'];
        }

        if ($this->isInTradingWindow($symbolCode, $now)) {
            return (int) $this->config['fast_interval_minutes'];
        }

        return (int) $this->config['slow_interval_minutes'];
    }

    public function isQuoteDue(?\DateTimeInterface $lastPulledAt, int $intervalMinutes, CarbonInterface $now): bool
    {
        if ($lastPulledAt === null) {
            return true;
        }

        $tz = (string) $this->config['timezone'];

        $lastPulled = Carbon::instance($lastPulledAt)->setTimezone($tz);
        $threshold = Carbon::instance($now)->setTimezone($tz)->subMinutes($intervalMinutes);

        return $lastPulled <= $threshold;
    }

    private function getMappedSessions(string $symbolCode): array
    {
        $base = substr($symbolCode, 0, 3);
        $quote = substr($symbolCode, 3, 3);

        $mappedSessions = [];

        // Get sessions for base currency
        if (isset($this->config['currencies'][$base])) {
            $mappedSessions = array_merge($mappedSessions, $this->config['currencies'][$base]);
        }

        // Get sessions for quote currency
        if (isset($this->config['currencies'][$quote])) {
            $mappedSessions = array_merge($mappedSessions, $this->config['currencies'][$quote]);
        }

        // Remove duplicates and return unique sessions
        return array_values(array_unique($mappedSessions));
    }

    public function debug(string $symbolCode, CarbonInterface $now, ?\DateTimeInterface $lastPulledAt, bool $hasOpenTrade): array
    {
        $nowCarbon = Carbon::instance($now)->setTimezone($this->config['timezone']);

        $base = substr($symbolCode, 0, 3);
        $quote = substr($symbolCode, 3, 3);

        $mappedSessions = $this->getMappedSessions($symbolCode);
        $activeSessions = [];

        foreach ($mappedSessions as $sessionName) {
            if ($this->isSessionActive($sessionName, $nowCarbon)) {
                $activeSessions[] = $sessionName;
            }
        }

        $inTradingWindow = $this->isInTradingWindow($symbolCode, $nowCarbon);
        $intervalMinutes = $this->syncIntervalMinutes($symbolCode, $nowCarbon, $hasOpenTrade);
        $isDue = $this->isQuoteDue($lastPulledAt, $intervalMinutes, $nowCarbon);

        return [
            'symbol' => $symbolCode,
            'now' => $nowCarbon->toDateTimeString(),
            'timezone' => $this->config['timezone'],
            'base' => $base,
            'quote' => $quote,
            'mapped_sessions' => $mappedSessions,
            'active_sessions' => $activeSessions,
            'in_trading_window' => $inTradingWindow,
            'has_open_trade' => $hasOpenTrade,
            'interval_minutes' => $intervalMinutes,
            'last_pulled_at' => $lastPulledAt
                ? Carbon::instance($lastPulledAt)->setTimezone($this->config['timezone'])->toDateTimeString()
                : null,
            'is_due' => $isDue,
        ];
    }

    public function mappedSessions(string $symbolCode): array
    {
        return $this->getMappedSessions($symbolCode);
    }

    public function sessionWindowForNow(string $sessionName, CarbonInterface $now): array
    {
        $session = $this->config['sessions'][$sessionName];
        $tz = (string) $this->config['timezone'];
        $now = Carbon::instance($now)->setTimezone($tz);

        $start = Carbon::createFromFormat('Y-m-d H:i', $now->format('Y-m-d') . ' ' . $session['start'], $tz);
        $end = Carbon::createFromFormat('Y-m-d H:i', $now->format('Y-m-d') . ' ' . $session['end'], $tz);

        if ($end <= $start) {
            $end->addDay();
        }

        $warmup = (int) ($session['warmup_minutes'] ?? 0);
        $cooldown = (int) ($session['cooldown_minutes'] ?? 0);

        $windowStart = $start->copy()->subMinutes($warmup);
        $windowEnd = $end->copy()->addMinutes($cooldown);

        return [
            'window_start' => $windowStart,
            'window_end' => $windowEnd,
        ];
    }

    public function isSessionActive(string $sessionName, CarbonInterface $now): bool
    {
        $session = $this->config['sessions'][$sessionName];

        $tz = (string) $this->config['timezone'];
        $now = Carbon::instance($now)->setTimezone($tz);

        $start = Carbon::createFromFormat('Y-m-d H:i', $now->format('Y-m-d') . ' ' . $session['start'], $tz);
        $end = Carbon::createFromFormat('Y-m-d H:i', $now->format('Y-m-d') . ' ' . $session['end'], $tz);

        if ($end <= $start) {
            $end->addDay();
        }

        $warmup = (int) ($session['warmup_minutes'] ?? 0);
        $cooldown = (int) ($session['cooldown_minutes'] ?? 0);

        $windowStart = $start->copy()->subMinutes($warmup);
        $windowEnd = $end->copy()->addMinutes($cooldown);

        // Check if now is between windowStart and windowEnd
        if ($now->between($windowStart, $windowEnd)) {
            return true;
        }

        // Also check the same window shifted by -1 day (for cross-midnight overlap)
        $prevWindowStart = $windowStart->copy()->subDay();
        $prevWindowEnd = $windowEnd->copy()->subDay();
        if ($now->between($prevWindowStart, $prevWindowEnd)) {
            return true;
        }

        return false;
    }

}
