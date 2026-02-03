<?php

namespace Database\Seeders;

use App\Models\Trade;
use App\Models\Symbol;
use App\Enums\TradeStatus;
use App\Enums\TimeframeCode;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class TradesTestSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Delete existing deterministic trades
        Trade::query()
            ->whereIn('id', [900001, 900002])
            ->delete();

        // Get available symbol codes from database, fallback to Binance symbols
        $availableCodes = Symbol::query()->pluck('code')->all();
        if (empty($availableCodes)) {
            $availableCodes = ['BTCUSDT', 'ETHUSDT'];
        }

        // Create deterministic test trades using available symbols
        Trade::query()->create([
            'id' => 900001,
            'symbol_code' => $availableCodes[0], // BTCUSDT or first available
            'timeframe_code' => TimeframeCode::M15->value,
            'side' => 'buy',
            'status' => TradeStatus::OPEN,
            'opened_at' => '2024-01-15 10:30:00',
            'closed_at' => null,
            'entry_price' => 78000.00,
            'exit_price' => null,
            'realized_points' => 0,
            'unrealized_points' => 0, // Runtime-only, keep default
            'meta' => null,
        ]);

        Trade::query()->create([
            'id' => 900002,
            'symbol_code' => $availableCodes[1] ?? $availableCodes[0], // ETHUSDT or fallback to first available
            'timeframe_code' => TimeframeCode::H1->value,
            'side' => 'sell',
            'status' => TradeStatus::CLOSED,
            'opened_at' => '2024-01-10 14:15:00',
            'closed_at' => '2024-01-12 16:45:00',
            'entry_price' => 2300.00,
            'exit_price' => 2200.00,
            'realized_points' => 100,
            'unrealized_points' => 0,
            'meta' => null,
        ]);
    }
}
