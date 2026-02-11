<?php

namespace App\Console\Commands\Trading;

use App\Contracts\StrategySettingsRepository;
use App\Enums\TradeStatus;
use App\Models\Candle;
use App\Models\Symbol;
use App\Models\SymbolQuote;
use App\Models\Trade;
use App\Models\TradeMonitor;
use App\Services\Trading\TradeDecisionService;
use Illuminate\Console\Command;

class RebuildTradeMonitors extends Command
{
    protected $signature = 'trading:rebuild-monitors';

    protected $description = 'Rebuild trade monitor rows for all active symbols and configured timeframes';

    public function handle(
        StrategySettingsRepository $strategySettings,
        TradeDecisionService $decisionService
    ): int {
        $this->info('Starting trade monitor rebuild...');

        $cfg = $strategySettings->get();
        $timeframes = array_values($cfg['timeframes'] ?? []);

        if ($timeframes === []) {
            $this->warn('No timeframes configured. Nothing to rebuild.');
            return Command::SUCCESS;
        }

        $this->info('Configured timeframes: ' . implode(', ', $timeframes));

        $activeSymbols = Symbol::query()
                               ->where('is_active', true)
                               ->get(['code']);

        $this->info('Found ' . $activeSymbols->count() . ' active symbols');

        if ($activeSymbols->isEmpty()) {
            $this->warn('No active symbols found. Nothing to rebuild.');
            return Command::SUCCESS;
        }

        $openTrades = Trade::query()
                           ->where('status', TradeStatus::OPEN->value)
                           ->get(['id', 'symbol_code', 'timeframe_code', 'side', 'status'])
                           ->keyBy(fn (Trade $t) => $t->symbol_code . '|' . $t->timeframe_code);

        $now = now();
        $rows = [];
        $processed = 0;
        $total = $activeSymbols->count() * count($timeframes);

        foreach ($activeSymbols as $symbol) {
            $symbolCode = (string) $symbol->code;

            // Entry decision computed once per symbol (used only when no open trade for a row)
            $decision = $decisionService->decideOpen($symbolCode);

            foreach ($timeframes as $tf) {
                $tf = (string) $tf;

                $key = $symbolCode . '|' . $tf;
                $openTrade = $openTrades->get($key);
                $openTradeId = $openTrade?->id;

                $rows[] = [
                    'symbol_code' => $symbolCode,
                    'timeframe_code' => $tf,
                    'expectation' => $openTrade
                        ? $this->computeExpectationForOpenTrade($openTrade, $symbolCode, $tf)
                        : $this->computeExpectationForNoTrade($decision, $tf),
                    'open_trade_id' => $openTradeId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                $processed++;

                if (count($rows) >= 500) {
                    TradeMonitor::query()->upsert(
                        $rows,
                        ['symbol_code', 'timeframe_code'],
                        ['expectation', 'open_trade_id', 'updated_at']
                    );
                    $rows = [];
                }

                if ($processed % 500 === 0) {
                    $this->info("Processed {$processed}/{$total} rows...");
                }
            }
        }

        if ($rows !== []) {
            TradeMonitor::query()->upsert(
                $rows,
                ['symbol_code', 'timeframe_code'],
                ['expectation', 'open_trade_id', 'updated_at']
            );
        }

        $this->info("Trade monitor rebuild completed. Processed {$processed} rows.");

        return Command::SUCCESS;
    }

    protected function computeExpectationForNoTrade(array $decision, string $rowTimeframe): string
    {
        $action = (string) ($decision['action'] ?? 'hold');
        $reason = (string) ($decision['reason'] ?? '');
        $debug = (array) ($decision['debug'] ?? []);

        // ✅ Persist the "WAIT:*" expectation for waiting_lower_reversal per-row timeframe
        if ($reason === 'waiting_lower_reversal') {
            $waiting = (array) ($debug['waiting_entries'] ?? []);

            foreach ($waiting as $w) {
                $entryTf = (string) ($w['entry_tf'] ?? '');
                if ($entryTf === '' || $entryTf !== $rowTimeframe) {
                    continue;
                }

                $wanted = strtoupper((string) ($w['wanted_dir'] ?? ''));
                $lowerNow = strtoupper((string) ($w['lower_dir_now'] ?? ''));
                $lowerTf = (string) ($w['lower_tf'] ?? '');
                $req = (string) ($w['required_seniors'] ?? '');
                $cnt = (string) ($w['seniors_in_dir_count'] ?? '');

                return "WAIT: entry={$entryTf} lower={$lowerTf} want={$wanted} lower_now={$lowerNow} seniors={$cnt}/{$req}";
            }

            return "No entry on {$rowTimeframe}";
        }

        if ($action !== 'open') {
            return match ($reason) {
                'no_edge' => 'No entry: no edge',
                'not_enough_candles' => 'No entry: not enough candles',
                'no_entry_timeframe' => 'No entry: no suitable entry timeframe',
                'all_candidates_flat' => 'No entry: all candidates are flat',
                default => 'No entry',
            };
        }

        $entryTf = (string) ($decision['timeframe_code'] ?? '');
        $side = (string) ($decision['side'] ?? '');

        if ($entryTf !== '' && $entryTf === $rowTimeframe) {
            if ($side === 'sell') {
                return "Wait on {$rowTimeframe}: HA DOWN to open SELL";
            }

            return "Wait on {$rowTimeframe}: HA UP to open BUY";
        }

        return "No entry on {$rowTimeframe}";
    }

    protected function computeExpectationForOpenTrade(Trade $trade, string $symbolCode, string $tradeTf): string
    {
        $side = (string) $trade->side;
        $exitTf = $this->lowerTimeframe($tradeTf);

        if ($exitTf === null) {
            return "Hold: HA on {$tradeTf} still in trend";
        }

        $exitDir = $this->haDirFromCurrentCandle($symbolCode, $exitTf);
        if ($exitDir === null) {
            return "Hold: HA on {$exitTf} still in trend";
        }

        $wantedExitDir = ($side === 'buy') ? 'up' : 'down';
        $isAgainst = ($exitDir !== 'flat') && ($exitDir !== $wantedExitDir);

        if (!$isAgainst) {
            return "Hold: HA on {$exitTf} still in trend";
        }

        $prevTradeCandle = Candle::query()
                                 ->where('symbol_code', $symbolCode)
                                 ->where('timeframe_code', $tradeTf)
                                 ->orderByDesc('open_time_ms')
                                 ->skip(1)
                                 ->first();

        if (!$prevTradeCandle) {
            return "Hold: HA on {$exitTf} still in trend";
        }

        $quote = SymbolQuote::query()
                            ->where('symbol_code', $symbolCode)
                            ->first();

        $priceNow = $quote?->price !== null ? (float) $quote->price : null;

        if ($side === 'buy') {
            $level = (float) $prevTradeCandle->low;

            if ($priceNow !== null && $priceNow < $level) {
                return "Exit now: price below prev {$tradeTf} low";
            }

            return "Exit signal: HA {$exitTf} turned DOWN → SL under prev {$tradeTf} low";
        }

        $level = (float) $prevTradeCandle->high;

        if ($priceNow !== null && $priceNow > $level) {
            return "Exit now: price above prev {$tradeTf} high";
        }

        return "Exit signal: HA {$exitTf} turned UP → SL above prev {$tradeTf} high";
    }

    private function lowerTimeframe(string $tf): ?string
    {
        return match ($tf) {
            '1d' => '4h',
            '4h' => '1h',
            '1h' => '30m',
            '30m' => '15m',
            '15m' => '5m',
            default => null,
        };
    }

    private function haDirFromCurrentCandle(string $symbolCode, string $tf): ?string
    {
        $candle = Candle::query()
                        ->where('symbol_code', $symbolCode)
                        ->where('timeframe_code', $tf)
                        ->orderByDesc('open_time_ms')
                        ->first();

        if (!$candle) {
            return null;
        }

        $haClose = ((float) $candle->open + (float) $candle->high + (float) $candle->low + (float) $candle->close) / 4.0;
        $haOpen = ((float) $candle->open + (float) $candle->close) / 2.0;

        if ($haClose > $haOpen) {
            return 'up';
        }
        if ($haClose < $haOpen) {
            return 'down';
        }

        return 'flat';
    }
}
