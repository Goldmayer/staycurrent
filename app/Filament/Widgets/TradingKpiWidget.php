<?php

namespace App\Filament\Widgets;

use App\Enums\TradeStatus;
use App\Models\Trade;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class TradingKpiWidget extends BaseWidget
{
    protected static ?int $sort = 1;

    protected $listeners = [
        'dashboard-refresh' => '$refresh',
    ];

    protected function getStats(): array
    {
        $today = now()->startOfDay();

        $openPnl = (float) Trade::query()
                                ->where('status', TradeStatus::OPEN->value)
                                ->sum('unrealized_points');

        $closedTodayPositive = (float) Trade::query()
                                            ->where('status', TradeStatus::CLOSED->value)
                                            ->where('closed_at', '>=', $today)
                                            ->where('realized_points', '>', 0)
                                            ->sum('realized_points');

        $closedTodayNegative = (float) Trade::query()
                                            ->where('status', TradeStatus::CLOSED->value)
                                            ->where('closed_at', '>=', $today)
                                            ->where('realized_points', '<', 0)
                                            ->sum('realized_points');

        $rExpr = "COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.close.r_multiple')) AS DECIMAL(14,2)), 0)";

        $rRow = Trade::query()
                     ->where('status', TradeStatus::CLOSED->value)
                     ->where('closed_at', '>=', $today)
                     ->selectRaw("SUM(CASE WHEN {$rExpr} > 0 THEN {$rExpr} ELSE 0 END) AS r_pos")
                     ->selectRaw("SUM(CASE WHEN {$rExpr} < 0 THEN {$rExpr} ELSE 0 END) AS r_neg")
                     ->first();

        $rPos = (float) ($rRow->r_pos ?? 0);
        $rNeg = (float) ($rRow->r_neg ?? 0); // отрицательное число
        $rNet = $rPos + $rNeg;

        $pfR = $rNeg < 0 ? ($rPos / abs($rNeg)) : null;

        return [
            Stat::make('Open P&L (pts)', number_format($openPnl, 2)),
            Stat::make('Closed today + (pts)', number_format($closedTodayPositive, 2)),
            Stat::make('Closed today - (pts)', number_format($closedTodayNegative, 2)),
            Stat::make('Closed today (R)', ($rNet > 0 ? '+' : '') . number_format($rNet, 2)),
            Stat::make('ProfitFactor (R)', $pfR === null ? '—' : number_format($pfR, 2)),
        ];
    }
}
