<?php

namespace App\Livewire\Dashboard;

use App\Models\Candle;
use App\Models\Trade;
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
        $this->debug_total_records = Trade::count();

        $query = Trade::query()
                      ->where('status', 'open')
                      ->with([
                          'symbol.quotes',
                      ]);

        $this->debug_table_records_count = (clone $query)->count();

        return $table
            ->query($query)->poll('5s')
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


                TextColumn::make('entry_reason')
                          ->label('Entry Reason')
                          ->getStateUsing(function (Trade $record): string {
                              $decision = data_get($record->meta, 'open.decision');

                              if (! $decision) {
                                  return 'Strategy entry (details unavailable)';
                              }

                              $side = data_get($decision, 'side');
                              $timeframeCode = data_get($decision, 'timeframe_code');
                              $currentTf = data_get($decision, 'debug.current_tf');
                              $voteTotal = data_get($decision, 'debug.vote_total');
                              $flatOk = data_get($decision, "debug.flat.{$timeframeCode}.ok");

                              if (! $side || ! $timeframeCode || ! $currentTf || $voteTotal === null) {
                                  return 'Strategy entry (details unavailable)';
                              }

                              $sideUpper = strtoupper((string) $side);
                              $flatStatus = $flatOk === true ? 'not flat' : "market was flat on {$timeframeCode}";

                              return "{$sideUpper} entry on {$timeframeCode}, confirmed by {$currentTf}. Market index {$voteTotal}. {$timeframeCode} {$flatStatus}.";
                          })
                          ->wrap(),

                TextColumn::make('opened_at')
                          ->label('Opened')
                          ->dateTime('Y-m-d H:i:s')
                          ->placeholder('—')
                          ->sortable(),

                TextColumn::make('entry_price')
                          ->label('Entry')
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 4, '.', ''))
                          ->sortable(),

                TextColumn::make('unrealized_points')
                          ->label('Unrealized (pts)')
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

                TextColumn::make('unrealized_r')
                          ->label('Unrealized (R)')
                          ->getStateUsing(function (Trade $record): ?float {
                              $risk = (float) ($record->stop_loss_points ?? 0);
                              if ($risk <= 0) {
                                  return null;
                              }

                              $u = (float) ($record->unrealized_points ?? 0);
                              return round($u / $risk, 2);
                          })
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
                          }),

                TextColumn::make('sl_left_points')
                          ->label('SL left')
                          ->getStateUsing(function (Trade $record): ?float {
                              $priceNow = (float) ($record->symbol?->quotes?->price ?? 0);
                              $pointSize = (float) ($record->symbol?->point_size ?? 0);

                              if ($priceNow <= 0 || $pointSize <= 0) {
                                  return null;
                              }

                              $entry = (float) ($record->entry_price ?? 0);
                              if ($entry <= 0) {
                                  return null;
                              }

                              $slPoints = (float) ($record->stop_loss_points ?? 0);
                              if ($slPoints <= 0) {
                                  return null;
                              }

                              $side = (string) ($record->side ?? '');
                              $exitStopPrice = data_get($record->meta, 'exit_stop.stop_price');

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


                TextColumn::make('expectation')
                          ->label('Expectation')
                    ->color(fn (?string $state): string => match (true) {
                        is_string($state) && str_starts_with($state, 'OK:') => 'success',
                        is_string($state) && str_starts_with($state, 'Exit:') => 'danger',
                        default => 'gray',
                    })
                          ->getStateUsing(function (Trade $record): ?string {
                              $entryTf = (string) ($record->timeframe_code ?? '');
                              $lowerTf = match ($entryTf) {
                                  '1d' => '4h',
                                  '4h' => '1h',
                                  '1h' => '30m',
                                  '30m' => '15m',
                                  '15m' => '5m',
                                  default => null,
                              };

                              if (! $lowerTf) {
                                  return '—';
                              }

                              $candle = Candle::query()
                                  ->where('symbol_code', $record->symbol_code)
                                  ->where('timeframe_code', $lowerTf)
                                  ->orderByDesc('open_time_ms')
                                  ->skip(1)
                                  ->first();

                              if (! $candle) {
                                  return '—';
                              }

                              $o = (float) $candle->open;
                              $h = (float) $candle->high;
                              $l = (float) $candle->low;
                              $c = (float) $candle->close;

                              $haClose = ($o + $h + $l + $c) / 4;
                              $haOpen = ($o + $c) / 2;

                              $dir = match (true) {
                                  $haClose > $haOpen => 'up',
                                  $haClose < $haOpen => 'down',
                                  default => 'flat',
                              };

                              $side = (string) ($record->side ?? '');
                              $reversed = match ($side) {
                                  'buy' => $dir === 'down',
                                  'sell' => $dir === 'up',
                                  default => false,
                              };

                              if ($reversed) {
                                  return "Exit: lower TF reversed ({$lowerTf})";
                              }

                              return "OK: lower TF still in trend ({$lowerTf})";
                          })
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
