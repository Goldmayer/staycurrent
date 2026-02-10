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
            'warmup_minutes' => 60,
            'cooldown_minutes' => 120,
            'sessions' => [
                'sydney' => ['start' => '22:00', 'end' => '07:00'],
                'tokyo' => ['start' => '00:00', 'end' => '09:00'],
                'london' => ['start' => '08:00', 'end' => '17:00'],
                'newyork' => ['start' => '13:00', 'end' => '22:00'],
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

        $sessions = [];

        if (in_array($base, ['AUD', 'NZD'], true) || in_array($quote, ['AUD', 'NZD'], true)) {
            $sessions[] = 'sydney';
        }

        if (in_array($base, ['JPY'], true) || in_array($quote, ['JPY'], true)) {
            $sessions[] = 'tokyo';
        }

        if (in_array($base, ['EUR', 'GBP', 'CHF'], true) || in_array($quote, ['EUR', 'GBP', 'CHF'], true)) {
            $sessions[] = 'london';
        }

        if (in_array($base, ['USD', 'CAD'], true) || in_array($quote, ['USD', 'CAD'], true)) {
            $sessions[] = 'newyork';
        }

        return $sessions;
    }

    private function isSessionActive(string $sessionName, CarbonInterface $now): bool
    {
        $session = $this->config['sessions'][$sessionName];

        $tz = (string) $this->config['timezone'];
        $now = Carbon::instance($now)->setTimezone($tz);

        $start = Carbon::createFromFormat('Y-m-d H:i', $now->format('Y-m-d') . ' ' . $session['start'], $tz);
        $end = Carbon::createFromFormat('Y-m-d H:i', $now->format('Y-m-d') . ' ' . $session['end'], $tz);

        if ($end <= $start) {
            $end->addDay();
        }

        $warmup = (int) ($this->config['warmup_minutes'] ?? 0);
        $cooldown = (int) ($this->config['cooldown_minutes'] ?? 0);

        $windowStart = $start->copy()->subMinutes($warmup);
        $windowEnd = $end->copy()->addMinutes($cooldown);

        // Also handle the "after midnight" part for cross-midnight sessions:
        // if now is before start time (early day), compare against the window shifted back by 1 day.
        if ($now->lt($windowStart) && $end->gt($start)) {
            $altStart = $windowStart->copy()->subDay();
            $altEnd = $windowEnd->copy()->subDay();
            if ($now->between($altStart, $altEnd)) {
                return true;
            }
        }

        return $now->between($windowStart, $windowEnd);
    }
}
