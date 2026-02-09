<?php

namespace App\Livewire\Dashboard;

use App\Models\Symbol;
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

        $activeTimeframes = $this->getActiveTimeframes();
        $activeSymbols = $this->getActiveSymbolCodes();

        $query = TradeMonitor::query()
                             ->whereNull('open_trade_id')
                             ->whereIn('timeframe_code', $activeTimeframes)
                             ->whereIn('symbol_code', $activeSymbols)
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
                            ->options(fn () => Symbol::query()
                                                     ->where('is_active', true)
                                                     ->orderBy('code')
                                                     ->pluck('code', 'code')
                                                     ->all()
                            ),
                SelectFilter::make('timeframe_code')
                            ->label('TF')
                            ->options(fn () => array_combine($activeTimeframes, $activeTimeframes)),
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

                TextColumn::make('tf_dir')
                          ->label('Dir')
                          ->badge()
                          ->getStateUsing(function (TradeMonitor $record): string {
                              $debug = $this->getMarketDebugForSymbol($record->symbol_code);
                              $dir = $debug['dirs'][$record->timeframe_code] ?? null;

                              return match ($dir) {
                                  'up' => 'UP',
                                  'down' => 'DOWN',
                                  default => 'FLAT',
                              };
                          })
                          ->color(function (TradeMonitor $record): string {
                              $debug = $this->getMarketDebugForSymbol($record->symbol_code);
                              $dir = $debug['dirs'][$record->timeframe_code] ?? null;

                              return match ($dir) {
                                  'up' => 'success',
                                  'down' => 'danger',
                                  default => 'gray',
                              };
                          }),

                TextColumn::make('market_index')
                          ->label('Market')
                          ->badge()
                          ->getStateUsing(function (TradeMonitor $record): string {
                              $debug = $this->getMarketDebugForSymbol($record->symbol_code);

                              $voteTotal = $debug['vote_total'];
                              $threshold = $debug['threshold'];

                              if ($voteTotal === null || $threshold === null || abs($voteTotal) < $threshold) {
                                  return 'No edge';
                              }

                              $direction = $voteTotal > 0 ? 'BUY' : 'SELL';

                              return "Index {$voteTotal} ({$direction})";
                          })
                          ->color(function (TradeMonitor $record): string {
                              $debug = $this->getMarketDebugForSymbol($record->symbol_code);

                              $voteTotal = $debug['vote_total'];
                              $threshold = $debug['threshold'];

                              if ($voteTotal === null || $threshold === null || abs($voteTotal) < $threshold) {
                                  return 'gray';
                              }

                              return $voteTotal > 0 ? 'success' : 'danger';
                          })
                          ->wrap(),

                TextColumn::make('tf_map')
                          ->label('TF map')
                          ->getStateUsing(function (TradeMonitor $record): string {
                              $debug = $this->getMarketDebugForSymbol($record->symbol_code);
                              $dirs = $debug['dirs'] ?? [];

                              $order = $this->getActiveTimeframes();
                              $parts = [];

                              foreach ($order as $tf) {
                                  $arrow = match ($dirs[$tf] ?? null) {
                                      'up' => '↑',
                                      'down' => '↓',
                                      default => '→',
                                  };

                                  $parts[] = "{$tf}{$arrow}";
                              }

                              return implode(' ', $parts);
                          })
                          ->wrap(),

                TextColumn::make('expectation')
                          ->label('Expectation')
                          ->getStateUsing(fn (TradeMonitor $record) => (string) ($record->expectation ?? '—'))
                          ->wrap()
                          ->color(fn (?string $state): string => match (true) {
                              is_string($state) && str_starts_with($state, 'OK:') => 'success',
                              is_string($state) && str_starts_with($state, 'Exit:') => 'danger',
                              default => 'gray',
                          }),
            ]);
    }

    private function getActiveTimeframes(): array
    {
        $timeframes = (array) config('trading.strategy.timeframes', []);

        return array_values(array_filter($timeframes, fn ($tf) => is_string($tf) && $tf !== ''));
    }

    private function getActiveSymbolCodes(): array
    {
        return Symbol::query()
                     ->where('is_active', true)
                     ->orderBy('code')
                     ->pluck('code')
                     ->all();
    }

    private function getMarketDebugForSymbol(string $symbolCode): array
    {
        static $cache = [];

        if (!isset($cache[$symbolCode])) {
            $decisionService = app(TradeDecisionService::class);
            $decision = $decisionService->decideOpen($symbolCode);

            $debug = $decision['debug'] ?? [];

            $cache[$symbolCode] = [
                'vote_total' => $debug['vote_total'] ?? null,
                'threshold' => $debug['threshold'] ?? null,
                'dirs' => (array) ($debug['dirs'] ?? []),
            ];
        }

        return $cache[$symbolCode];
    }

    public function render(): View
    {
        return view('livewire.dashboard.trades-waiting', [
            'debug_total_records' => $this->debug_total_records,
            'debug_table_records_count' => $this->debug_table_records_count,
        ]);
    }
}
