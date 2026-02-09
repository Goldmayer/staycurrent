<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('market:sync')
        ->everyFiveMinutes()
        ->appendOutputTo(storage_path('logs/schedule-market-sync.log'));

Schedule::command('trade:tick')
        ->everyFiveMinutes()
        ->appendOutputTo(storage_path('logs/schedule-trade-tick.log'));

Schedule::command('trade:close')
        ->everyFiveMinutes()
        ->appendOutputTo(storage_path('logs/schedule-trade-close.log'));

Schedule::command('trading:rebuild-monitors')
        ->everyFiveMinutes()
        ->appendOutputTo(storage_path('logs/schedule-trade-monitors.log'));

