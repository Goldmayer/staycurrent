<?php

namespace Database\Seeders;

use App\Models\Trade;
use App\Enums\TradeStatus;
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

        // Create deterministic test trades
        Trade::query()->create([
            'id' => 900001,
            'symbol_code' => 'EURUSD',
            'timeframe_code' => 'M15',
            'side' => 'buy',
            'status' => TradeStatus::OPEN,
            'opened_at' => '2024-01-15 10:30:00',
            'closed_at' => null,
            'entry_price' => 1.10500,
            'exit_price' => null,
            'realized_points' => 0,
            'unrealized_points' => 15.25,
            'meta' => null,
        ]);

        Trade::query()->create([
            'id' => 900002,
            'symbol_code' => 'GBPUSD',
            'timeframe_code' => 'H1',
            'side' => 'sell',
            'status' => TradeStatus::CLOSED,
            'opened_at' => '2024-01-10 14:15:00',
            'closed_at' => '2024-01-12 16:45:00',
            'entry_price' => 1.27800,
            'exit_price' => 1.26500,
            'realized_points' => 130,
            'unrealized_points' => 0,
            'meta' => null,
        ]);
    }
}
