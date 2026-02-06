<?php

namespace App\Console\Commands;

use App\Services\Trading\TradeCloseService;
use Illuminate\Console\Command;

class TradeClose extends Command
{
    protected $signature = 'trade:close {--limit= : Limit open trades processed}';

    protected $description = 'Close open virtual trades based on Heikin Ashi reversal; exit by current quote price';

    public function handle(TradeCloseService $service): int
    {
        $limitRaw = $this->option('limit');
        $limit = is_numeric($limitRaw) ? (int) $limitRaw : null;

        $this->info('Starting trade close process...');

        try {
            $result = $service->process($limit);

            $this->info("Processed {$result['trades_processed']} open trades");
            $this->info("Closed {$result['trades_closed']} trades");
            $this->info("Held {$result['trades_held']} trades");

            $this->line("Skipped missing quote: {$result['skipped_missing_quote']}");
            $this->line("Skipped stale quote: {$result['skipped_stale_quote']}");
            $this->line("Skipped missing symbol: {$result['skipped_missing_symbol']}");
            $this->line("Skipped not enough candles: {$result['skipped_not_enough_candles']}");
            $this->line("Skipped no reversal: {$result['skipped_no_reversal']}");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Error during trade close process: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
