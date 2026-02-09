<?php

namespace App\Livewire\Dashboard;

use App\Models\Candle;
use App\Models\Trade;
use Carbon\CarbonImmutable;
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

    private function timeframeMs(string $tf): int
    {
        return match ($tf) {
            '5m' => 5 * 60 * 1000,
            '15m' => 15 * 60 * 1000,
            '30m' => 30 * 60 * 1000,
            '1h' => 60 * 60 * 1000,
            '4h' => 4 * 60 * 60 * 1000,
            '1d' => 24 * 60 * 60 * 1000,
            default => 60 * 60 * 1000,
        };
    }

    private function isQuoteStale(?\DateTimeInterface $pulledAt): bool
    {
        if (! $pulledAt) {
            return true;
        }

        return CarbonImmutable::instance($pulledAt)->lt(now()->subMinutes(12));
    }

    private function isCandleStale(?int $lastOpenMs, string $tf): bool
    {
        if (! $lastOpenMs) {
            return true;
        }

        $lastCloseMs = $lastOpenMs + $this->timeframeMs($tf);
        $ageSeconds = (int) floor(((int) now()->getTimestamp() * 1000 - $lastCloseMs) / 1000);
        $thresholdSeconds = (int) ceil(($this->timeframeMs($tf) / 1000) * 2.2);

        return $ageSeconds > $thresholdSeconds;
    }

    public function table(Table $table): Table
    {
        $this->debug_total_records = Trade::count();

        $query = Trade::query()
                      ->where('status', 'open')
                      ->with([
                          'symbol.quotes',
                      ])
                      ->addSelect([
                          'last_candle_open_time_ms' => Candle::query()
                                                              ->selectRaw('MAX(open_time_ms)')
                                                              ->whereColumn('candles.symbol_code', 'trades.symbol_code')
                                                              ->whereColumn('candles.timeframe_code', 'trades.timeframe_code'),
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

                TextColumn::make('quote_freshness')
                          ->label('Quote')
                          ->getStateUsing(function (Trade $record): string {
                              $pulledAt = $record->symbol?->quotes?->pulled_at ?? $record->symbol?->quotes?->updated_at;
                              if (! $pulledAt) {
                                  return '—';
                              }
                              return CarbonImmutable::instance($pulledAt)->diffForHumans();
                          })
                          ->color(function (Trade $record): string {
                              $pulledAt = $record->symbol?->quotes?->pulled_at ?? $record->symbol?->quotes?->updated_at;
                              return $this->isQuoteStale($pulledAt) ? 'danger' : 'success';
                          })
                          ->tooltip(function (Trade $record): ?string {
                              $pulledAt = $record->symbol?->quotes?->pulled_at ?? $record->symbol?->quotes?->updated_at;
                              return $pulledAt ? CarbonImmutable::instance($pulledAt)->format('Y-m-d H:i:s') : null;
                          }),

                TextColumn::make('candle_freshness')
                          ->label('Candles')
                          ->getStateUsing(function (Trade $record): string {
                              $lastOpenMs = (int) ($record->last_candle_open_time_ms ?? 0);
                              if ($lastOpenMs <= 0) {
                                  return '—';
                              }

                              return CarbonImmutable::createFromTimestampMs($lastOpenMs)->diffForHumans();
                          })
                          ->color(function (Trade $record): string {
                              $tf = (string) ($record->timeframe_code ?? '1h');
                              $lastOpenMs = (int) ($record->last_candle_open_time_ms ?? 0);

                              if ($lastOpenMs <= 0) {
                                  return 'danger';
                              }

                              // свеча считается устаревшей если старше ~2 таймфреймов
                              $ageSeconds = now()->diffInSeconds(CarbonImmutable::createFromTimestampMs($lastOpenMs));
                              $threshold = ($this->timeframeMs($tf) / 1000) * 2.2;

                              return $ageSeconds > $threshold ? 'danger' : 'success';
                          })
                          ->tooltip(function (Trade $record): ?string {
                              $lastOpenMs = (int) ($record->last_candle_open_time_ms ?? 0);
                              return $lastOpenMs > 0
                                  ? CarbonImmutable::createFromTimestampMs($lastOpenMs)->format('Y-m-d H:i:s')
                                  : null;
                          }),


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
