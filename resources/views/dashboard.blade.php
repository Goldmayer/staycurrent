<x-layouts::app :title="__('Dashboard')">

    <section class="mx-auto w-full max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
        <header class="mb-4 flex items-start justify-between gap-3">
            <div>
                <h1 class="text-base font-semibold text-slate-900">Dashboard</h1>
                <p class="mt-1 text-sm text-slate-500">Compact grid, calm UI.</p>
            </div>

            <div class="flex items-center gap-2">
                <button
                    type="button"
                    class="inline-flex items-center rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-700 shadow-sm hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-slate-300"
                >
                    Action
                </button>
                <button
                    type="button"
                    class="inline-flex items-center rounded-lg bg-slate-900 px-3 py-2 text-sm font-medium text-white shadow-sm hover:bg-slate-800 focus:outline-none focus:ring-2 focus:ring-slate-400"
                >
                    Primary
                </button>
            </div>
        </header>

        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-6">
            <!-- Card -->
            <article class="rounded-xl border border-slate-200 bg-white p-3 shadow-sm">
                <div class="flex items-center justify-between">
                    <p class="text-xs font-medium text-slate-500">Label</p>
                    <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600">Tag</span>
                </div>
                <p class="mt-2 text-lg font-semibold text-slate-900">12 480</p>
                <p class="mt-1 text-xs text-slate-500">Secondary text</p>
            </article>

            <article class="rounded-xl border border-slate-200 bg-white p-3 shadow-sm">
                <div class="flex items-center justify-between">
                    <p class="text-xs font-medium text-slate-500">Label</p>
                    <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600">Tag</span>
                </div>
                <p class="mt-2 text-lg font-semibold text-slate-900">3.42%</p>
                <p class="mt-1 text-xs text-slate-500">Secondary text</p>
            </article>

            <article class="rounded-xl border border-slate-200 bg-white p-3 shadow-sm">
                <div class="flex items-center justify-between">
                    <p class="text-xs font-medium text-slate-500">Label</p>
                    <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600">Tag</span>
                </div>
                <p class="mt-2 text-lg font-semibold text-slate-900">9</p>
                <p class="mt-1 text-xs text-slate-500">Secondary text</p>
            </article>

            <article class="rounded-xl border border-slate-200 bg-white p-3 shadow-sm">
                <div class="flex items-center justify-between">
                    <p class="text-xs font-medium text-slate-500">Label</p>
                    <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600">Tag</span>
                </div>
                <p class="mt-2 text-lg font-semibold text-slate-900">1:24</p>
                <p class="mt-1 text-xs text-slate-500">Secondary text</p>
            </article>

            <article class="rounded-xl border border-slate-200 bg-white p-3 shadow-sm">
                <div class="flex items-center justify-between">
                    <p class="text-xs font-medium text-slate-500">Label</p>
                    <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600">Tag</span>
                </div>
                <p class="mt-2 text-lg font-semibold text-slate-900">€ 6 120</p>
                <p class="mt-1 text-xs text-slate-500">Secondary text</p>
            </article>

            <article class="rounded-xl border border-slate-200 bg-white p-3 shadow-sm">
                <div class="flex items-center justify-between">
                    <p class="text-xs font-medium text-slate-500">Label</p>
                    <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600">Tag</span>
                </div>
                <p class="mt-2 text-lg font-semibold text-slate-900">OK</p>
                <p class="mt-1 text-xs text-slate-500">Secondary text</p>
            </article>

            <!-- Wide block example (optional): spans 3 cols on lg -->
            <article class="rounded-xl border border-slate-200 bg-white p-3 shadow-sm lg:col-span-3">
                <p class="text-xs font-medium text-slate-500">Wide panel</p>
                <div class="mt-2 h-24 rounded-lg border border-dashed border-slate-200 bg-slate-50"></div>
                <p class="mt-2 text-xs text-slate-500">Put chart/table/etc here.</p>
            </article>

            <!-- Another wide block (optional): spans 3 cols on lg -->
            <article class="rounded-xl border border-slate-200 bg-white p-3 shadow-sm lg:col-span-3">
                <p class="text-xs font-medium text-slate-500">Wide panel</p>
                <div class="mt-2 h-24 rounded-lg border border-dashed border-slate-200 bg-slate-50"></div>
                <p class="mt-2 text-xs text-slate-500">Put chart/table/etc here.</p>
            </article>
        </div>
    </section>
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
