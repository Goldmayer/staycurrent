<?php

namespace App\Console\Commands;

use App\Services\Trading\TradeTickService;
use Illuminate\Console\Command;

class TradeTick extends Command
{
    protected $signature = 'trade:tick {--limit=} {--presetId=}';
    protected $description = 'Evaluate signals and open trades';

    public function handle(TradeTickService $service): int
    {
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;

        $this->info('Starting trade tick process...');

        $result = $service->process($limit);

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
