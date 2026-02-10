<?php

namespace App\Services\MarketData;

use App\Contracts\MarketDataProvider;
use App\Models\PriceTick;
use App\Models\SymbolQuote;
use Illuminate\Support\Facades\DB;

class MarketDataSyncService
{
    private readonly MarketDataProvider $provider;

    public function __construct(MarketDataProvider $provider)
    {
        $this->provider = $provider;
    }

    public function syncSymbol(string $symbolCode): void
    {
        $this->syncSymbolQuote($symbolCode);
    }

    public function syncSymbolQuote(string $symbolCode): void
    {
        try {
            $price = $this->provider->lastPrice($symbolCode);

            if ($price === null || $price <= 0) {
                throw new \RuntimeException("Provider lastPrice returned invalid value for {$symbolCode}");
            }

            $now = now();

            SymbolQuote::updateOrCreate(
                ['symbol_code' => $symbolCode],
                [
                    'price' => $price,
                    'source' => $this->provider->source(),
                    'pulled_at' => $now,
                    'updated_at' => $now,
                ]
            );

            PriceTick::create([
                'symbol_code' => $symbolCode,
                'price' => $price,
                'pulled_at' => $now,
            ]);

            $count = DB::table('price_ticks')
                       ->where('symbol_code', $symbolCode)
                       ->count();

            if ($count > 2000) {
                $cutoffId = DB::table('price_ticks')
                              ->where('symbol_code', $symbolCode)
                              ->orderByDesc('id')
                              ->offset(1999)
                              ->limit(1)
                              ->value('id');

                if ($cutoffId) {
                    DB::table('price_ticks')
                      ->where('symbol_code', $symbolCode)
                      ->where('id', '<', $cutoffId)
                      ->delete();
                }
            }
        } catch (\Exception $e) {
            SymbolQuote::query()
                       ->where('symbol_code', $symbolCode)
                       ->update([
                           'source' => 'provider_error',
                           'pulled_at' => now(),
                           'updated_at' => now(),
                       ]);

            report($e);
        }
    }
}
