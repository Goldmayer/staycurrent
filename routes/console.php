<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('market:sync')
        ->everyMinute()
        ->appendOutputTo(storage_path('logs/schedule-market-sync.log'));

Schedule::command('trade:tick')
        ->everyMinute()
        ->appendOutputTo(storage_path('logs/schedule-trade-tick.log'));

Schedule::command('trade:close')
        ->everyMinute()
        ->appendOutputTo(storage_path('logs/schedule-trade-close.log'));

Schedule::command('trading:rebuild-monitors')
        ->everyMinute()
        ->appendOutputTo(storage_path('logs/schedule-trade-monitors.log'));

