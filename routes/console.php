<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Register custom commands
//require __DIR__.'/../app/Console/Commands/TradeTick.php';

use Illuminate\Support\Facades\Schedule;

Schedule::command('market:sync')->everyFiveMinutes();
Schedule::command('trade:tick')->everyFiveMinutes();
Schedule::command('trade:close')->everyFiveMinutes();

