<?php

namespace App\Livewire\Dashboard;

use App\Models\Trade;
use App\Services\Trading\FxSyncModeService;
use App\Services\Trading\PriceWindowService;
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

    public array $fx_mode_cards = [];

    public function getUpdateFrequencies(): array
    {
        $scheduler = app(\App\Services\Trading\FxSessionScheduler::class);
        $now = now();

        $symbols = \App\Models\Symbol::query()
            ->where('is_active', true)
            ->pluck('code');

        $openTrades = \App\Models\Trade::query()
            ->where('status', 'open')
            ->pluck('symbol_code')
            ->unique()
            ->flip();

        $quotes = \App\Models\SymbolQuote::query()
            ->whereIn('symbol_code', $symbols)
            ->get()
            ->keyBy('symbol_code');

        $data = [];

        foreach ($symbols as $code) {
            $hasOpenTrade = isset($openTrades[$code]);
            $quote = $quotes[$code] ?? null;
            $lastPulledAt = $quote?->pulled_at ?? $quote?->updated_at;

            $debug = $scheduler->debug($code, $now, $lastPulledAt, $hasOpenTrade);

            $interval = (int) $debug['interval_minutes'];

            // Рассчитываем время следующего обновления
            if ($lastPulledAt) {
                $nextUpdateAt = \Illuminate\Support\Carbon::instance($lastPulledAt)->addMinutes($interval);
                $secondsLeft = max(0, $now->diffInSeconds($nextUpdateAt, false));
            } else {
                $secondsLeft = 0;
            }

            $data[] = [
                'symbol' => $code,
                'interval' => $interval,
                'sessions' => $debug['active_sessions'],
                'has_trade' => $debug['has_open_trade'],
                'seconds_left' => $secondsLeft,
            ];
        }

        return $data;
    }

    protected function getTablePaginationPageName(): string
    {
        return 'tm_open_page';
    }

    public function mount(): void
    {
        $this->resetTable();
        $this->refreshFxModeCards();
    }

    public function refreshFxModeCards(): void
    {
        $service = app(FxSyncModeService::class);
        $this->fx_mode_cards = $service->getModeCards(now());
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

    private function tradeStatus(Trade $record): string
    {
        return $record->status instanceof \BackedEnum ? $record->status->value : (string) $record->status;
    }

    public function table(Table $table): Table
    {
        $this->debug_total_records = Trade::count();

        return $table
            ->query(Trade::query()->whereIn('status', ['open', 'pending'])->with(['symbol.quotes']))
            ->poll('5s')->defaultPaginationPageOption(10)
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
                        if ($value === 'pending') {
                            return 'warning';
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

                        return CarbonImmutable::instance($pulledAt)->diffForHumans(null, true, 3);
                    })
                    ->color(function (Trade $record): string {
                        $pulledAt = $record->symbol?->quotes?->pulled_at ?? $record->symbol?->quotes?->updated_at;

                        return $this->isQuoteStale($pulledAt) ? 'danger' : 'success';
                    })
                    ->tooltip(function (Trade $record): ?string {
                        $pulledAt = $record->symbol?->quotes?->pulled_at ?? $record->symbol?->quotes?->updated_at;

                        return $pulledAt ? CarbonImmutable::instance($pulledAt)->format('Y-m-d H:i:s') : null;
                    }),

                TextColumn::make('entry_reason')
                    ->label('Entry Reason')
                    ->getStateUsing(function (Trade $record): string {
                        $status = $this->tradeStatus($record);
                        if ($status === 'pending') {
                            return 'Pending order waiting for fill';
                        }

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
                    ->formatStateUsing(function ($state, Trade $record): string {
                        $status = $this->tradeStatus($record);
                        if ($status === 'pending') {
                            return '—';
                        }
                        if ($state === null) {
                            return '—';
                        }
                        $v = (float) $state;

                        return ($v > 0 ? '+' : '').number_format($v, 2);
                    })
                    ->color(function ($state, Trade $record): string {
                        $status = $this->tradeStatus($record);
                        if ($status === 'pending') {
                            return 'gray';
                        }
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

                TextColumn::make('unrealized_r')->toggleable(isToggledHiddenByDefault: true)
                    ->label('Unrealized (R)')
                    ->getStateUsing(function (Trade $record): ?float {
                        $status = $this->tradeStatus($record);
                        if ($status === 'pending') {
                            return null;
                        }
                        $risk = (float) ($record->stop_loss_points ?? 0);
                        if ($risk <= 0) {
                            return null;
                        }

                        $u = (float) ($record->unrealized_points ?? 0);

                        return round($u / $risk, 2);
                    })
                    ->formatStateUsing(function ($state, Trade $record): string {
                        $status = $this->tradeStatus($record);
                        if ($status === 'pending') {
                            return '—';
                        }
                        if ($state === null) {
                            return '—';
                        }
                        $v = (float) $state;

                        return ($v > 0 ? '+' : '').number_format($v, 2);
                    })
                    ->color(function ($state, Trade $record): string {
                        $status = $this->tradeStatus($record);
                        if ($status === 'pending') {
                            return 'gray';
                        }
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
                        $status = $this->tradeStatus($record);
                        if ($status === 'pending') {
                            return null;
                        }
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
                    ->formatStateUsing(function ($state, Trade $record): string {
                        $status = $this->tradeStatus($record);
                        if ($status === 'pending') {
                            return '—';
                        }
                        if ($state === null) {
                            return '—';
                        }

                        return number_format((float) $state, 2);
                    })
                    ->color(function ($state, Trade $record): string {
                        $status = $this->tradeStatus($record);
                        if ($status === 'pending') {
                            return 'gray';
                        }
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
                        if ($this->tradeStatus($record) === 'pending') {
                            return '—';
                        }

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

                        $priceWindows = (array) config('trading.strategy.price_windows', []);
                        $flatThresholdPct = (float) ($priceWindows['dir_flat_threshold_pct'] ?? 0.0001);

                        $tfCfg = (array) (($priceWindows['timeframes'][$lowerTf] ?? null) ?? []);
                        $minutes = (int) ($tfCfg['minutes'] ?? 0);
                        $points = (int) ($tfCfg['points'] ?? 0);

                        if ($minutes <= 0 || $points <= 0) {
                            return '—';
                        }

                        /** @var PriceWindowService $svc */
                        $svc = app(PriceWindowService::class);

                        $w = $svc->window(
                            symbolCode: (string) $record->symbol_code,
                            minutes: $minutes,
                            points: $points,
                            dirFlatThresholdPct: $flatThresholdPct
                        );

                        $dir = (string) ($w['dir'] ?? 'no_data');
                        if ($dir === 'no_data') {
                            return '—';
                        }

                        if ($dir === 'flat') {
                            return "FLAT lower TF ({$lowerTf})";
                        }

                        $dirPct = isset($w['dir_pct']) ? (float) $w['dir_pct'] : null;

                        $minStrength = (float) (config('trading.exit.reversal_min_strength_pct', 0.00015));
                        $side = (string) ($record->side ?? '');

                        $against = match ($side) {
                            'buy' => $dir === 'down',
                            'sell' => $dir === 'up',
                            default => false,
                        };

                        $strongEnough = $dirPct !== null && $dirPct >= $minStrength;

                        if ($against && $strongEnough) {
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
