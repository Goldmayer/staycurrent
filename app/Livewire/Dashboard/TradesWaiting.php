<?php

namespace App\Livewire\Dashboard;

use App\Models\TradeMonitor;
use App\Services\Trading\TradeDecisionService;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class TradesWaiting extends Component implements HasActions, HasForms, HasTable
{
    use InteractsWithActions;
    use InteractsWithForms;
    use InteractsWithTable;

    protected string $paginationTheme = 'tailwind';

    public int $debug_total_records = 0;
    public int $debug_table_records_count = 0;

    protected function getTablePaginationPageName(): string
    {
        return 'tm_wait_page';
    }

    public function mount(): void
    {
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        $this->debug_total_records = TradeMonitor::count();

        $query = TradeMonitor::query()
                             ->whereNull('open_trade_id')
                             ->with('openTrade');

        $this->debug_table_records_count = (clone $query)->count();

        return $table
            ->query($query)
            ->poll('5s')
            ->defaultPaginationPageOption(10)
            ->paginationPageOptions([10, 25, 50])
            ->filters([
                SelectFilter::make('symbol_code')
                            ->label('Symbol')
                            ->options(fn () => TradeMonitor::query()
                                                           ->select('symbol_code')
                                                           ->distinct()
                                                           ->orderBy('symbol_code')
                                                           ->pluck('symbol_code', 'symbol_code')
                                                           ->all()
                            ),
                SelectFilter::make('timeframe_code')
                            ->label('TF')
                            ->options(fn () => TradeMonitor::query()
                                                           ->select('timeframe_code')
                                                           ->distinct()
                                                           ->orderBy('timeframe_code')
                                                           ->pluck('timeframe_code', 'timeframe_code')
                                                           ->all()
                            ),
            ])
            ->columns([
                TextColumn::make('symbol_code')
                          ->label('Symbol')
                          ->searchable()
                          ->sortable(),

                TextColumn::make('timeframe_code')
                          ->label('TF')
                          ->searchable()
                          ->sortable(),

                TextColumn::make('expectation')
                          ->label('Expectation')
                          ->getStateUsing(fn (TradeMonitor $record) => (string) ($record->expectation ?? '—'))
                          ->wrap(),

                TextColumn::make('market_summary')
                          ->label('Market')
                          ->getStateUsing(function (TradeMonitor $record) {
                              return $this->getMarketSummaryForSymbol($record->symbol_code);
                          })
                          ->wrap(),
            ]);
    }

    private function getMarketSummaryForSymbol(string $symbolCode): string
    {
        static $marketCache = [];

        if (!isset($marketCache[$symbolCode])) {
            $decisionService = app(TradeDecisionService::class);
            $decision = $decisionService->decideOpen($symbolCode);

            if (isset($decision['debug']['vote_total'], $decision['debug']['threshold'])) {
                $voteTotal = $decision['debug']['vote_total'];
                $threshold = $decision['debug']['threshold'];

                if (abs($voteTotal) < $threshold) {
                    $marketCache[$symbolCode] = 'No edge';
                } else {
                    $direction = $voteTotal > 0 ? 'BUY' : 'SELL';
                    $marketCache[$symbolCode] = "Market index: {$voteTotal} ({$direction})";
                }
            } else {
                $marketCache[$symbolCode] = 'No edge';
            }
        }

        return $marketCache[$symbolCode];
    }

    public function render(): View
    {
        return view('livewire.dashboard.trades-waiting', [
            'debug_total_records' => $this->debug_total_records,
            'debug_table_records_count' => $this->debug_table_records_count,
        ]);
    }
}
