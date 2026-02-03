<?php

namespace Database\Seeders;

use App\Models\Symbol;
use Illuminate\Database\Seeder;

class SymbolsSeeder extends Seeder
{
    public function run(): void
    {
        $symbols = [
            [
                'code' => 'BTCUSDT',
                'is_active' => true,
                'sort' => 1,
                'point_size' => 1,
                'price_decimals' => 2,
            ],
            [
                'code' => 'ETHUSDT',
                'is_active' => true,
                'sort' => 2,
                'point_size' => 1,
                'price_decimals' => 2,
            ],
        ];

        foreach ($symbols as $symbolData) {
            Symbol::updateOrCreate(
                ['code' => $symbolData['code']],
                $symbolData
            );
        }
    }
}
