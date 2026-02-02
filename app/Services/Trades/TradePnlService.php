<?php

namespace App\Services\Trades;

use App\Enums\TradeStatus;
use App\Models\Trade;

class TradePnlService
{
    public function pointsFromPrices(string $side, float $entryPrice, float $currentPrice): float
    {
        $entryPrice = (float) $entryPrice;
        $currentPrice = (float) $currentPrice;

        if ($side === 'buy') {
            return $currentPrice - $entryPrice;
        }

        if ($side === 'sell') {
            return $entryPrice - $currentPrice;
        }

        return 0.0;
    }

    public function updateUnrealized(Trade $trade, float $currentPrice): Trade
    {
        if ($trade->isOpen()) {
            $trade->unrealized_points = $this->pointsFromPrices(
                $trade->side,
                $trade->entry_price,
                $currentPrice
            );
            $trade->save();
        }

        return $trade;
    }

    public function closeTrade(Trade $trade, float $exitPrice): Trade
    {
        if ($trade->isOpen()) {
            $trade->exit_price = $exitPrice;
            $trade->status = TradeStatus::CLOSED;
            $trade->realized_points = $this->pointsFromPrices(
                $trade->side,
                $trade->entry_price,
                $exitPrice
            );
            $trade->unrealized_points = 0;
            $trade->save();
        }

        return $trade;
    }
}
