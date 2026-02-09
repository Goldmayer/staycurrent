<x-layouts::app :title="__('Dashboard')">


    <div class="flex h-full w-full flex-1 flex-col gap-4 rounded-xl">

        @livewire(\App\Filament\Widgets\TradingKpiWidget::class)
{{--        @livewire(\App\Filament\Widgets\SymbolPnlCardsWidget::class)--}}


        @include('dashboard._refresh-progress')
        <livewire:dashboard.trades-monitor />
<livewire:dashboard.trades-waiting />
        <livewire:dashboard.trades-history />

    </div>
    <div
        wire:poll.60s="$dispatch('dashboard-refresh')"
        class="hidden"
    ></div>
</x-layouts::app>
