<?php

namespace App\Filament\Widgets;

use App\Enums\TradeStatus;
use App\Models\Trade;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\DB;

class SymbolPnlCardsWidget extends Widget
{
    protected int|string|array $columnSpan = 1;


    protected string $view = 'filament.widgets.symbol-pnl-cards-widget';


    protected static ?int $sort = 2;

    protected $listeners = [
        'dashboard-refresh' => '$refresh',
    ];

    protected function getViewData(): array
    {
        $today = now('UTC')->startOfDay();
        $todayStr = $today->toDateTimeString();

        $rows = Trade::query()
                     ->select([
                         'symbol_code',
                         'timeframe_code',
                         DB::raw("SUM(CASE WHEN status = '" . TradeStatus::OPEN->value . "' THEN unrealized_points ELSE 0 END) as open_points"),
                         DB::raw("
                             SUM(
                                 CASE
                                     WHEN status = '" . TradeStatus::CLOSED->value . "'
                                      AND closed_at >= '{$todayStr}'
                                     THEN realized_points ELSE 0
                                 END
                             ) as closed_today_points
                         "),
                     ])
                     ->groupBy('symbol_code', 'timeframe_code')
                     ->orderBy('symbol_code')
                     ->get();

        $symbols = [];

        foreach ($rows as $r) {
            $sym = (string) $r->symbol_code;
            $tf = (string) $r->timeframe_code;

            $open = (float) $r->open_points;
            $closed = (float) $r->closed_today_points;

            if (!isset($symbols[$sym])) {
                $symbols[$sym] = [
                    'symbol' => $sym,
                    'open_total' => 0.0,
                    'closed_today_total' => 0.0,
                    'open_by_tf' => [],
                    'closed_today_by_tf' => [],
                ];
            }

            $symbols[$sym]['open_total'] += $open;
            $symbols[$sym]['closed_today_total'] += $closed;

            $symbols[$sym]['open_by_tf'][$tf] = $open;
            $symbols[$sym]['closed_today_by_tf'][$tf] = $closed;
        }

        $totalOpen = 0.0;
        $totalClosedToday = 0.0;

        foreach ($symbols as $s) {
            $totalOpen += $s['open_total'];
            $totalClosedToday += $s['closed_today_total'];
        }

        foreach ($symbols as &$s) {
            $s['symbol_color'] = $s['open_total'] > 0 ? 'success'
                : ($s['open_total'] < 0 ? 'danger' : 'gray');
        }

        return [
            'symbols' => array_values($symbols),
            'portfolio_open_total' => $totalOpen,
            'portfolio_closed_today_total' => $totalClosedToday,
            'portfolio_open_color' => $totalOpen > 0 ? 'success'
                : ($totalOpen < 0 ? 'danger' : 'gray'),
            'portfolio_closed_today_color' => $totalClosedToday > 0 ? 'success'
                : ($totalClosedToday < 0 ? 'danger' : 'gray'),
        ];
    }
}
