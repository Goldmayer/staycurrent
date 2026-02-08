<?php

namespace App\Livewire\Dashboard;

use App\Models\Trade;
use App\Models\TradeMonitor;
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

class TradesMonitor extends Component implements HasActions, HasForms, HasTable
{
    use InteractsWithActions;
    use InteractsWithForms;
    use InteractsWithTable;

    protected string $paginationTheme = 'tailwind';

    public int $debug_total_records = 0;
    public int $debug_table_records_count = 0;

    protected function getTablePaginationPageName(): string
    {
        return 'tm_open_page';
    }

    public function mount(): void
    {
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        $this->debug_total_records = TradeMonitor::count();

        $query = TradeMonitor::query()
                             ->whereNotNull('open_trade_id')
                             ->whereHas('openTrade', function ($q) {
                                 $q->where('status', 'open');
                             })
                             ->with([
                                 'openTrade.symbol.quotes',
                             ]);

        $this->debug_table_records_count = (clone $query)->count();

        return $table
            ->query($query)->poll('5s')
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
                          ->searchable()
                          ->label('TF')
                          ->sortable(),

                TextColumn::make('openTrade.side')
                          ->label('Side')
                          ->badge()
                          ->formatStateUsing(fn ($state) => $state ? strtoupper((string) $state) : '—')
                          ->color(fn ($state) => $state === 'buy' ? 'success' : ($state === 'sell' ? 'danger' : 'gray'))
                          ->sortable(),

                TextColumn::make('openTrade.status')
                          ->label('Status')
                          ->badge()
                          ->formatStateUsing(function ($state): string {
                              if ($state === null || $state === '') {
                                  return '—';
                              }
                              $value = $state instanceof \BackedEnum ? $state->value : (string) $state;
                              return ucfirst($value);
                          })
                          ->color(function ($state): string {
                              $value = $state instanceof \BackedEnum ? $state->value : (string) $state;
                              if ($value === 'open') {
                                  return 'info';
                              }
                              if ($value === 'closed') {
                                  return 'gray';
                              }
                              return 'gray';
                          })
                          ->sortable(),

                TextColumn::make('openTrade.opened_at')
                          ->label('Opened')
                          ->dateTime('Y-m-d H:i:s')
                          ->placeholder('—')
                          ->sortable(),

                TextColumn::make('openTrade.closed_at')
                          ->label('Closed')
                          ->dateTime('Y-m-d H:i:s')
                          ->placeholder('—')
                          ->sortable(),

                TextColumn::make('openTrade.entry_price')
                          ->label('Entry')
                          ->formatStateUsing(fn ($state) => $state === null ? '—' : number_format((float) $state, 8))
                          ->sortable(),

                TextColumn::make('openTrade.exit_price')
                          ->label('Exit')
                          ->formatStateUsing(fn ($state) => $state === null ? '—' : number_format((float) $state, 8))
                          ->sortable(),

                TextColumn::make('openTrade.unrealized_points')
                          ->label('Unrealized')
                          ->formatStateUsing(function ($state): string {
                              if ($state === null) {
                                  return '—';
                              }
                              $v = (float) $state;
                              return ($v > 0 ? '+' : '') . number_format($v, 2);
                          })
                          ->color(function ($state): string {
                              if ($state === null) {
                                  return 'gray';
                              }
                              $v = (float) $state;
                              if ($v > 0) {
                                  return 'success';
                              }
                              if ($v < 0) {
                                  return 'danger';
                              }
                              return 'gray';
                          })
                          ->sortable(),

                TextColumn::make('openTrade.realized_points')
                          ->label('P&L')
                          ->formatStateUsing(function ($state): string {
                              if ($state === null) {
                                  return '—';
                              }
                              $v = (float) $state;
                              return ($v > 0 ? '+' : '') . number_format($v, 2);
                          })
                          ->color(function ($state): string {
                              if ($state === null) {
                                  return 'gray';
                              }
                              $v = (float) $state;
                              if ($v > 0) {
                                  return 'success';
                              }
                              if ($v < 0) {
                                  return 'danger';
                              }
                              return 'gray';
                          })
                          ->sortable(),

                TextColumn::make('sl_left_points')
                          ->label('SL left')
                          ->getStateUsing(function (TradeMonitor $record): ?float {
                              /** @var Trade|null $t */
                              $t = $record->openTrade;
                              if (!$t) {
                                  return null;
                              }

                              $priceNow = (float) ($t->symbol?->quotes?->price ?? 0);
                              $pointSize = (float) ($t->symbol?->point_size ?? 0);

                              if ($priceNow <= 0 || $pointSize <= 0) {
                                  return null;
                              }

                              $entry = (float) ($t->entry_price ?? 0);
                              if ($entry <= 0) {
                                  return null;
                              }

                              $slPoints = (float) ($t->stop_loss_points ?? 0);
                              if ($slPoints <= 0) {
                                  return null;
                              }

                              $side = (string) ($t->side ?? '');
                              $exitStopPrice = data_get($t->meta, 'exit_stop.stop_price');

                              $stopPrice = $exitStopPrice !== null
                                  ? (float) $exitStopPrice
                                  : ($side === 'sell'
                                      ? ($entry + ($slPoints * $pointSize))
                                      : ($entry - ($slPoints * $pointSize))
                                  );

                              $left = $side === 'sell'
                                  ? (($stopPrice - $priceNow) / $pointSize)
                                  : (($priceNow - $stopPrice) / $pointSize);

                              return round($left, 2);
                          })
                          ->formatStateUsing(fn ($state) => $state === null ? '—' : number_format((float) $state, 2))
                          ->color(function ($state): string {
                              if ($state === null) {
                                  return 'gray';
                              }
                              return ((float) $state) <= 0 ? 'danger' : 'gray';
                          })
                          ->sortable(),

                TextColumn::make('tp_left_points')
                          ->label('TP left')
                          ->getStateUsing(function (TradeMonitor $record): ?float {
                              /** @var Trade|null $t */
                              $t = $record->openTrade;
                              if (!$t) {
                                  return null;
                              }

                              $priceNow = (float) ($t->symbol?->quotes?->price ?? 0);
                              $pointSize = (float) ($t->symbol?->point_size ?? 0);

                              if ($priceNow <= 0 || $pointSize <= 0) {
                                  return null;
                              }

                              $entry = (float) ($t->entry_price ?? 0);
                              if ($entry <= 0) {
                                  return null;
                              }

                              $tpPoints = (float) ($t->take_profit_points ?? 0);
                              if ($tpPoints <= 0) {
                                  return null;
                              }

                              $side = (string) ($t->side ?? '');

                              $tpPrice = $side === 'sell'
                                  ? ($entry - ($tpPoints * $pointSize))
                                  : ($entry + ($tpPoints * $pointSize));

                              $left = $side === 'sell'
                                  ? (($priceNow - $tpPrice) / $pointSize)
                                  : (($tpPrice - $priceNow) / $pointSize);

                              return round($left, 2);
                          })
                          ->formatStateUsing(fn ($state) => $state === null ? '—' : number_format((float) $state, 2))
                          ->color(function ($state): string {
                              if ($state === null) {
                                  return 'gray';
                              }
                              return ((float) $state) <= 0 ? 'success' : 'gray';
                          })
                          ->sortable(),

                TextColumn::make('openTrade.max_hold_minutes')
                          ->label('Max hold')
                          ->formatStateUsing(fn ($state) => $state === null ? '—' : (string) (int) $state)
                          ->sortable(),

                TextColumn::make('expectation')
                          ->label('Expectation')
                          ->getStateUsing(fn (TradeMonitor $record) => (string) ($record->expectation ?? '—'))
                          ->wrap(),
            ]);
    }

    public function render(): View
    {
        return view('livewire.dashboard.trades-monitor', [
            'debug_total_records' => $this->debug_total_records,
            'debug_table_records_count' => $this->debug_table_records_count,
        ]);
    }
}
