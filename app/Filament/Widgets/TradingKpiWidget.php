<?php

namespace App\Filament\Widgets;

use App\Enums\TradeStatus;
use App\Models\Trade;
use Filament\Widgets\Widget;

class TradingKpiWidget extends Widget
{
    protected static ?int $sort = 1;

    protected string $view = 'filament.widgets.trading-kpi-widget';

    protected ?string $pollingInterval = '60s';

    public function getPollingInterval(): ?string
    {
        return $this->pollingInterval;
    }

    protected function getViewData(): array
    {
        $today = now()->startOfDay(); // UTC (app timezone is UTC)

        $statusOpen = TradeStatus::OPEN->value;
        $statusClosed = TradeStatus::CLOSED->value;
        $statusPending = defined(TradeStatus::class . '::PENDING')
            ? TradeStatus::PENDING->value
            : 'pending';

        $openPnl = (float) Trade::query()
                                ->where('status', $statusOpen)
                                ->sum('unrealized_points');

        $closedTodayNet = (float) Trade::query()
                                       ->where('status', $statusClosed)
                                       ->where('closed_at', '>=', $today)
                                       ->sum('realized_points');

        $closedTodayPositive = (float) Trade::query()
                                            ->where('status', $statusClosed)
                                            ->where('closed_at', '>=', $today)
                                            ->where('realized_points', '>', 0)
                                            ->sum('realized_points');

        $closedTodayNegative = (float) Trade::query()
                                            ->where('status', $statusClosed)
                                            ->where('closed_at', '>=', $today)
                                            ->where('realized_points', '<', 0)
                                            ->sum('realized_points');

        $closedTodayCount = (int) Trade::query()
                                       ->where('status', $statusClosed)
                                       ->where('closed_at', '>=', $today)
                                       ->count();

        $closedTodayWins = (int) Trade::query()
                                      ->where('status', $statusClosed)
                                      ->where('closed_at', '>=', $today)
                                      ->where('realized_points', '>', 0)
                                      ->count();

        $winRate = $closedTodayCount > 0
            ? round(($closedTodayWins / $closedTodayCount) * 100, 2)
            : null;

        $openedTodayCount = (int) Trade::query()
                                       ->where('status', $statusOpen)
                                       ->where('opened_at', '>=', $today)
                                       ->count();

        $openNowCount = (int) Trade::query()
                                   ->where('status', $statusOpen)
                                   ->count();

        $pendingNowCount = (int) Trade::query()
                                      ->where('status', $statusPending)
                                      ->count();

        $rExpr = "COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.close.r_multiple')) AS DECIMAL(14,2)), 0)";

        $rRow = Trade::query()
                     ->where('status', $statusClosed)
                     ->where('closed_at', '>=', $today)
                     ->selectRaw("SUM(CASE WHEN {$rExpr} > 0 THEN {$rExpr} ELSE 0 END) AS r_pos")
                     ->selectRaw("SUM(CASE WHEN {$rExpr} < 0 THEN {$rExpr} ELSE 0 END) AS r_neg")
                     ->first();

        $rPos = (float) ($rRow->r_pos ?? 0);
        $rNeg = (float) ($rRow->r_neg ?? 0);
        $rNet = $rPos + $rNeg;

        $pfR = $rNeg < 0 ? ($rPos / abs($rNeg)) : null;

        $netToday = $closedTodayNet + $openPnl;

        $yStart = now()->subDay()->startOfDay();
        $yEnd = now()->startOfDay();

        $wStart = now()->startOfWeek();
        $wEnd = now();

        $yNet = (float) Trade::query()
                             ->where('status', $statusClosed)
                             ->where('closed_at', '>=', $yStart)
                             ->where('closed_at', '<', $yEnd)
                             ->sum('realized_points');

        $yCount = (int) Trade::query()
                             ->where('status', $statusClosed)
                             ->where('closed_at', '>=', $yStart)
                             ->where('closed_at', '<', $yEnd)
                             ->count();

        $yWins = (int) Trade::query()
                            ->where('status', $statusClosed)
                            ->where('closed_at', '>=', $yStart)
                            ->where('closed_at', '<', $yEnd)
                            ->where('realized_points', '>', 0)
                            ->count();

        $yWinRate = $yCount > 0 ? round(($yWins / $yCount) * 100, 2) : null;

        $yPos = (float) Trade::query()
                             ->where('status', $statusClosed)
                             ->where('closed_at', '>=', $yStart)
                             ->where('closed_at', '<', $yEnd)
                             ->where('realized_points', '>', 0)
                             ->sum('realized_points');

        $yNeg = (float) Trade::query()
                             ->where('status', $statusClosed)
                             ->where('closed_at', '>=', $yStart)
                             ->where('closed_at', '<', $yEnd)
                             ->where('realized_points', '<', 0)
                             ->sum('realized_points');

        $yPf = $yNeg < 0 ? ($yPos / abs($yNeg)) : null;

        $wNet = (float) Trade::query()
                             ->where('status', $statusClosed)
                             ->where('closed_at', '>=', $wStart)
                             ->where('closed_at', '<=', $wEnd)
                             ->sum('realized_points');

        $wCount = (int) Trade::query()
                             ->where('status', $statusClosed)
                             ->where('closed_at', '>=', $wStart)
                             ->where('closed_at', '<=', $wEnd)
                             ->count();

        $wWins = (int) Trade::query()
                            ->where('status', $statusClosed)
                            ->where('closed_at', '>=', $wStart)
                            ->where('closed_at', '<=', $wEnd)
                            ->where('realized_points', '>', 0)
                            ->count();

        $wWinRate = $wCount > 0 ? round(($wWins / $wCount) * 100, 2) : null;

        $wPos = (float) Trade::query()
                             ->where('status', $statusClosed)
                             ->where('closed_at', '>=', $wStart)
                             ->where('closed_at', '<=', $wEnd)
                             ->where('realized_points', '>', 0)
                             ->sum('realized_points');

        $wNeg = (float) Trade::query()
                             ->where('status', $statusClosed)
                             ->where('closed_at', '>=', $wStart)
                             ->where('closed_at', '<=', $wEnd)
                             ->where('realized_points', '<', 0)
                             ->sum('realized_points');

        $wPf = $wNeg < 0 ? ($wPos / abs($wNeg)) : null;

        $yTone = $yNet > 0 ? 'pos' : ($yNet < 0 ? 'neg' : 'neutral');
        $wTone = $wNet > 0 ? 'pos' : ($wNet < 0 ? 'neg' : 'neutral');

        $cards = [
            $this->cardPts('Open P&L (pts)', $openPnl, 'LIVE', 'Unrealized points, open trades', true),
            $this->cardPts('Closed today + (pts)', $closedTodayPositive, 'TODAY', 'Sum of positive realized points', true),
            $this->cardPts('Closed today - (pts)', $closedTodayNegative, 'TODAY', 'Sum of negative realized points', true),
            $this->cardPts('Closed today net (pts)', $closedTodayNet, 'TODAY', 'Closed + and - combined', true),
            $this->cardPts('Net today (pts)', $netToday, 'NOW', 'Closed net + Open P&L', true),
            $this->cardR('Closed today (R)', $rNet, 'R', 'Sum of R-multiple (closed today)'),
            $this->cardText('ProfitFactor (R)', $pfR === null ? '—' : number_format($pfR, 2), 'R', 'R+ / |R-|'),
            $this->cardText('Closed today (count)', (string) $closedTodayCount, 'TRADES', 'How many trades closed today'),
            $this->cardText('Opened today (count)', (string) $openedTodayCount, 'TRADES', 'How many trades opened today'),
            $this->cardText('Win rate today (%)', $winRate === null ? '—' : number_format($winRate, 2) . '%', 'TODAY', 'Wins / closed today'),
            $this->cardText('Open now (count)', (string) $openNowCount, 'LIVE', 'Open positions right now'),
            $this->cardText('Pending now (count)', (string) $pendingNowCount, 'LIVE', 'Pending orders right now'),
        ];

        return [
            'cards' => $cards,
            'todayUtc' => $today->toDateTimeString(),
            'yesterday' => [
                'net' => $yNet,
                'count' => $yCount,
                'win_rate' => $yWinRate,
                'pf' => $yPf,
                'tone' => $yTone,
            ],
            'week' => [
                'net' => $wNet,
                'count' => $wCount,
                'win_rate' => $wWinRate,
                'pf' => $wPf,
                'tone' => $wTone,
            ],
        ];
    }

    private function cardText(string $label, string $value, string $tag, string $secondary): array
    {
        return [
            'label' => $label,
            'value' => $value,
            'tag' => $tag,
            'secondary' => $secondary,
            'tone' => 'neutral',
        ];
    }

    private function cardPts(string $label, float $value, string $tag, string $secondary, bool $signed = true): array
    {
        $tone = $value > 0 ? 'pos' : ($value < 0 ? 'neg' : 'neutral');

        $formatted = number_format($value, 2);
        if ($signed && $value > 0) {
            $formatted = '+' . $formatted;
        }

        return [
            'label' => $label,
            'value' => $formatted,
            'tag' => $tag,
            'secondary' => $secondary,
            'tone' => $tone,
        ];
    }

    private function cardR(string $label, float $value, string $tag, string $secondary): array
    {
        $tone = $value > 0 ? 'pos' : ($value < 0 ? 'neg' : 'neutral');

        $formatted = number_format($value, 2);
        if ($value > 0) {
            $formatted = '+' . $formatted;
        }

        return [
            'label' => $label,
            'value' => $formatted,
            'tag' => $tag,
            'secondary' => $secondary,
            'tone' => $tone,
        ];
    }
}
