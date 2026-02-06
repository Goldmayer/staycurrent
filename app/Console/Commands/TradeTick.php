<?php

namespace App\Console\Commands;

use App\Services\Trading\TradeTickService;
use Illuminate\Console\Command;

class TradeTick extends Command
{
    protected $signature = 'trade:tick {--limit= : Limit active symbols processed}';

    protected $description = 'Open virtual trades based on multi-timeframe analysis inputs';

    public function handle(TradeTickService $tradeTickService): int
    {
        $limit = $this->option('limit');

        $this->info('Starting trade tick process...');

        try {
            $result = $tradeTickService->process($limit);

            $this->info("Processed {$result['symbols_processed']} symbols");
            $this->info("Opened {$result['trades_opened']} new trades");
            $this->info("Skipped {$result['trades_skipped']} existing trades");

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Error during trade tick process: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
