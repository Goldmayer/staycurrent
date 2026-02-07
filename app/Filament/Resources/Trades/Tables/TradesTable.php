<?php

namespace App\Livewire\Dashboard;

use App\Models\Trade;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class TradesTable extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    protected string $paginationTheme = 'tailwind';

    public function table(Table $table): Table
    {
        return $table
            ->query(Trade::query()->latest('id'))
            ->paginationPageOptions([10, 25, 50])
            ->defaultPaginationPageOption(10)
            ->columns([
                TextColumn::make('id')
                          ->label('ID')
                          ->sortable(),

                TextColumn::make('symbol_code')
                          ->label('Symbol')
                          ->searchable()
                          ->sortable(),

                TextColumn::make('timeframe_code')
                          ->label('Timeframe')
                          ->sortable(),

                TextColumn::make('side')
                          ->label('Side')
                          ->badge()
                          ->formatStateUsing(fn (?string $state) => strtoupper((string) $state))
                          ->color(fn (?string $state) => $state === 'buy' ? 'success' : 'danger')
                          ->sortable(),

                TextColumn::make('status')
                          ->label('Status')
                          ->badge()
                          ->formatStateUsing(function ($state): string {
                              $value = $state instanceof \BackedEnum ? $state->value : (string) $state;
                              return ucfirst($value);
                          })
                          ->color(function ($state): string {
                              $value = $state instanceof \BackedEnum ? $state->value : (string) $state;
                              return $value === 'open' ? 'info' : 'gray';
                          })
                          ->sortable(),

                TextColumn::make('opened_at')
                          ->label('Opened At')
                          ->dateTime('Y-m-d H:i:s')
                          ->sortable(),

                TextColumn::make('closed_at')
                          ->label('Closed At')
                          ->dateTime('Y-m-d H:i:s')
                          ->placeholder('—')
                          ->sortable(),

                TextColumn::make('entry_price')
                          ->label('Entry Price')
                          ->formatStateUsing(fn ($state) => $state === null ? '—' : number_format((float) $state, 8))
                          ->sortable(),

                TextColumn::make('exit_price')
                          ->label('Exit Price')
                          ->formatStateUsing(fn ($state) => $state === null ? '—' : number_format((float) $state, 8))
                          ->sortable(),

                TextColumn::make('unrealized_points')
                          ->label('Unrealized')
                          ->formatStateUsing(function ($state, Trade $record): string {
                              if (!$record->isOpen()) {
                                  return '—';
                              }
                              $v = (float) ($state ?? 0);
                              return ($v > 0 ? '+' : '') . number_format($v, 2);
                          })
                          ->color(function ($state, Trade $record): string {
                              if (!$record->isOpen()) {
                                  return 'gray';
                              }
                              $v = (float) ($state ?? 0);
                              if ($v > 0) {
                                  return 'success';
                              }
                              if ($v < 0) {
                                  return 'danger';
                              }
                              return 'gray';
                          })
                          ->sortable(),

                TextColumn::make('realized_points')
                          ->label('P&L Points')
                          ->formatStateUsing(function ($state): string {
                              $v = (float) ($state ?? 0);
                              return ($v > 0 ? '+' : '') . number_format($v, 2);
                          })
                          ->color(function ($state): string {
                              $v = (float) ($state ?? 0);
                              if ($v > 0) {
                                  return 'success';
                              }
                              if ($v < 0) {
                                  return 'danger';
                              }
                              return 'gray';
                          })
                          ->sortable(),

                TextColumn::make('stop_loss_points')
                          ->label('SL (pts)')
                          ->formatStateUsing(fn ($state) => $state === null ? '—' : number_format((float) $state, 2))
                          ->sortable(),

                TextColumn::make('take_profit_points')
                          ->label('TP (pts)')
                          ->formatStateUsing(fn ($state) => $state === null ? '—' : number_format((float) $state, 2))
                          ->sortable(),

                TextColumn::make('max_hold_minutes')
                          ->label('Max hold (min)')
                          ->formatStateUsing(fn ($state) => $state === null ? '—' : (string) (int) $state)
                          ->sortable(),
            ]);
    }

    public function render(): View
    {
        return view('livewire.dashboard.trades-table');
    }
}
