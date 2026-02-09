<?php

namespace App\Providers;

use App\Contracts\FxQuotesProvider;
use App\Contracts\MarketDataProvider;
use App\Contracts\StrategySettingsRepository;
use App\Services\MarketData\FxQuotesProviderPool;
use App\Services\MarketData\TwelveDataFxQuotesProvider;
use App\Services\MarketData\TwelveDataMarketDataProvider;
use App\Services\Trading\ConfigStrategySettingsRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(StrategySettingsRepository::class, ConfigStrategySettingsRepository::class);
        $this->app->bind(
            MarketDataProvider::class,
            TwelveDataMarketDataProvider::class
        );

        // Bind FX quotes providers
        $this->app->singleton(FxQuotesProviderPool::class, function ($app) {
            $providers = [
                $app->make(TwelveDataFxQuotesProvider::class),
            ];

            return new FxQuotesProviderPool($providers);
        });

        // Bind FxQuotesProvider to FxQuotesProviderPool as singleton
        $this->app->singleton(FxQuotesProvider::class, function ($app) {
            return $app->make(FxQuotesProviderPool::class);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
    }

    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null
        );
    }
}
