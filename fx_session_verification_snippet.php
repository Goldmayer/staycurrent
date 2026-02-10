<?php

// FX Session Scheduling Verification Snippet
// Run this in Laravel tinker to verify scheduling for any symbol

use App\Services\Trading\FxSessionScheduler;
use Illuminate\Support\Carbon;

function verifyFxSessionScheduling(string $symbolCode, ?Carbon $now = null): void
{
    $scheduler = new FxSessionScheduler();
    $now = $now ?? Carbon::now();

    // Get mapped sessions for the symbol
    $reflection = new ReflectionClass($scheduler);
    $getMappedSessionsMethod = $reflection->getMethod('getMappedSessions');
    $getMappedSessionsMethod->setAccessible(true);
    $mappedSessions = $getMappedSessionsMethod->invoke($scheduler, $symbolCode);

    // Check if any session is active
    $inTradingWindow = $scheduler->isInTradingWindow($symbolCode, $now);

    // Test with and without open trade
    $intervalWithTrade = $scheduler->syncIntervalMinutes($symbolCode, $now, true);
    $intervalWithoutTrade = $scheduler->syncIntervalMinutes($symbolCode, $now, false);

    // Simulate last pulled time (10 minutes ago)
    $lastPulledAt = $now->copy()->subMinutes(10);
    $isDueWithTrade = $scheduler->isQuoteDue($lastPulledAt, $intervalWithTrade, $now);
    $isDueWithoutTrade = $scheduler->isQuoteDue($lastPulledAt, $intervalWithoutTrade, $now);

    echo "=== FX Session Scheduling Verification ===\n";
    echo "Symbol: {$symbolCode}\n";
    echo "Now: {$now->format('Y-m-d H:i:s T')}\n";
    echo "Mapped Sessions: " . implode(', ', $mappedSessions) . "\n";
    echo "In Trading Window: " . ($inTradingWindow ? 'YES' : 'NO') . "\n";
    echo "Interval (with open trade): {$intervalWithTrade} minutes\n";
    echo "Interval (no open trade): {$intervalWithoutTrade} minutes\n";
    echo "Last Pulled At: {$lastPulledAt->format('Y-m-d H:i:s T')}\n";
    echo "Is Due (with trade): " . ($isDueWithTrade ? 'YES' : 'NO') . "\n";
    echo "Is Due (no trade): " . ($isDueWithoutTrade ? 'YES' : 'NO') . "\n";
    echo "\n";
}

// Example usage:
// verifyFxSessionScheduling('EURUSD');
// verifyFxSessionScheduling('GBPUSD');
// verifyFxSessionScheduling('USDJPY');
// verifyFxSessionScheduling('AUDUSD');
// verifyFxSessionScheduling('USDCAD');

// Test with specific time (e.g., during London session):
// $londonTime = Carbon::parse('2026-02-10 10:00:00', 'UTC');
// verifyFxSessionScheduling('EURUSD', $londonTime);

// Test with specific time (e.g., during weekend):
// $weekendTime = Carbon::parse('2026-02-15 12:00:00', 'UTC'); // Sunday
// verifyFxSessionScheduling('EURUSD', $weekendTime);
