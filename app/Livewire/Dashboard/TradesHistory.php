<?php

namespace App\Livewire\Dashboard;

use App\Enums\TradeStatus;
use App\Models\Trade;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class TradesHistory extends Component implements HasActions, HasForms, HasTable
{
    use InteractsWithActions;
    use InteractsWithForms;
    use InteractsWithTable;

    protected string $paginationTheme = 'tailwind';

    public int $debug_total_records = 0;
    public int $debug_table_records_count = 0;

    protected function getTablePaginationPageName(): string
    {
        return 'tm_hist_page';
    }

    public function mount(): void
    {
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        $this->debug_total_records = Trade::count();

        $query = Trade::query();
        $this->debug_table_records_count = (clone $query)->count();

        return $table
            ->query($query)
            ->poll('5s')
            ->defaultPaginationPageOption(10)
            ->paginationPageOptions([10, 25, 50])
            ->filters([
                SelectFilter::make('symbol_code')
                            ->label('Symbol')
                            ->options(fn () => Trade::query()
                                                    ->select('symbol_code')
                                                    ->distinct()
                                                    ->orderBy('symbol_code')
                                                    ->pluck('symbol_code', 'symbol_code')
                                                    ->all()
                            ),
                SelectFilter::make('timeframe_code')
                            ->label('TF')
                            ->options(fn () => Trade::query()
                                                    ->select('timeframe_code')
                                                    ->distinct()
                                                    ->orderBy('timeframe_code')
                                                    ->pluck('timeframe_code', 'timeframe_code')
                                                    ->all()
                            ),
                SelectFilter::make('status')
                            ->label('Status')
                            ->options([
                                'open' => 'Open',
                                'closed' => 'Closed',
                            ])
                            ->default('closed'),
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

                TextColumn::make('side')
                          ->label('Side')
                          ->badge()
                          ->formatStateUsing(fn ($state) => $state ? strtoupper((string) $state) : '—')
                          ->color(fn ($state) => $state === 'buy' ? 'success' : ($state === 'sell' ? 'danger' : 'gray'))
                          ->sortable(),

                TextColumn::make('status')
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

                TextColumn::make('opened_at')
                          ->label('Opened')
                          ->dateTime('Y-m-d H:i:s')
                          ->placeholder('—')
                          ->sortable(),

                TextColumn::make('closed_at')
                          ->label('Closed')
                          ->dateTime('Y-m-d H:i:s')
                          ->placeholder('—')
                          ->sortable(),

                TextColumn::make('entry_price')
                          ->label('Entry')
                          ->formatStateUsing(fn ($state) => $state === null ? '—' : number_format((float) $state, 8))
                          ->sortable(),

                TextColumn::make('exit_price')
                          ->label('Exit')
                          ->formatStateUsing(fn ($state) => $state === null ? '—' : number_format((float) $state, 8))
                          ->sortable(),

                TextColumn::make('realized_points')->summarize(Sum::make())
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
            ]);
    }

    public function render(): View
    {
        return view('livewire.dashboard.trades-history', [
            'debug_total_records' => $this->debug_total_records,
            'debug_table_records_count' => $this->debug_table_records_count,
        ]);
    }
}
