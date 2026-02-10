<?php

namespace App\Services\Trading;

use Illuminate\Support\Facades\DB;

final class PriceWindowService
{
    public function window(string $symbolCode, int $minutes, int $points, float $dirFlatThresholdPct = 0.0001): array
    {
        $now = now();

        $currentWindowStart = $now->copy()->subMinutes($minutes);
        $previousWindowStart = $now->copy()->subMinutes($minutes * 2);

        $currentTicks = $this->getTicks($symbolCode, $currentWindowStart, null, $points);
        $previousTicks = $this->getTicks($symbolCode, $previousWindowStart, $currentWindowStart, $points);

        $currentStats = $this->computeStats($currentTicks, $points);
        $previousStats = $this->computeStats($previousTicks, $points);

        $direction = $this->computeDirection($currentStats['avg'], $previousStats['avg'], $dirFlatThresholdPct);

        return [
            'symbol_code' => $symbolCode,
            'minutes' => $minutes,
            'points' => $points,
            'now' => $now->toISOString(),
            'current' => $currentStats,
            'previous' => $previousStats,
            'dir' => $direction['dir'],
            'dir_pct' => $direction['pct'],
        ];
    }

    private function getTicks(string $symbolCode, \DateTimeInterface $from, ?\DateTimeInterface $to, int $limit): array
    {
        $query = DB::table('price_ticks')
            ->where('symbol_code', $symbolCode)
            ->where('pulled_at', '>=', $from);

        if ($to) {
            $query->where('pulled_at', '<', $to);
        }

        return $query
            ->orderBy('id', 'desc')
            ->limit($limit)
            ->pluck('price')
            ->map(fn($v) => (float)$v)
            ->toArray();
    }

    private function computeStats(array $ticks, int $points): array
    {
        $count = count($ticks);

        if ($count === 0) {
            return [
                'avg' => null,
                'min' => null,
                'max' => null,
                'range' => null,
                'count' => 0,
                'is_complete' => false,
            ];
        }

        $avg = array_sum($ticks) / $count;
        $min = min($ticks);
        $max = max($ticks);
        $range = $max - $min;

        return [
            'avg' => $avg,
            'min' => $min,
            'max' => $max,
            'range' => $range,
            'count' => $count,
            'is_complete' => $count >= $points,
        ];
    }

    private function computeDirection(?float $currAvg, ?float $prevAvg, float $threshold): array
    {
        if ($currAvg === null || $prevAvg === null) {
            return ['dir' => 'no_data', 'pct' => null];
        }

        $diff = abs($currAvg - $prevAvg);
        $pct = $diff / $currAvg;

        if ($pct < $threshold) {
            return ['dir' => 'flat', 'pct' => $pct];
        }

        if ($currAvg > $prevAvg) {
            return ['dir' => 'up', 'pct' => $pct];
        }

        return ['dir' => 'down', 'pct' => $pct];
    }
}
