<?php

namespace App\Console\Commands;

use App\Services\Trading\TradeTickService;
use Illuminate\Console\Command;

class TradeTick extends Command
{
    protected $signature = 'trade:tick
        {--limit= : Limit active symbols processed}
        {--presetId= : (legacy, ignored)}
        {--symbol= : Process only specific symbol code}
        {--force : Force open trade (bypass decision)}
        {--force-side= : buy|sell (used with --force, default: buy)}
        {--force-tf= : Timeframe code (used with --force, default: 15m)}
    ';

    protected $description = 'Evaluate signals and open trades';

    public function handle(TradeTickService $service): int
    {
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;

        $symbol = $this->option('symbol');
        $symbol = is_string($symbol) && $symbol !== '' ? strtoupper($symbol) : null;

        $force = (bool) $this->option('force');

        $forceSide = $this->option('force-side');
        $forceSide = is_string($forceSide) && $forceSide !== '' ? strtolower($forceSide) : null;

        $forceTf = $this->option('force-tf');
        $forceTf = is_string($forceTf) && $forceTf !== '' ? (string) $forceTf : null;

        $this->info('Starting trade tick process...');

        $result = $service->process(
            limit: $limit,
            onlySymbol: $symbol,
            forceOpen: $force,
            forceSide: $forceSide,
            forceTimeframe: $forceTf,
        );

        $this->info("Processed {$result['symbols_processed']} symbols");
        $this->info("Opened {$result['trades_opened']} new trades");
        $this->info("Skipped {$result['trades_skipped']} symbols (see breakdown)");

        $skipped = $result['skipped'] ?? [];
        if (!empty($skipped)) {
            $this->line('Skip breakdown:');
            foreach ($skipped as $k => $v) {
                $this->line("  - {$k}: {$v}");
            }
        }

        return self::SUCCESS;
    }
}
