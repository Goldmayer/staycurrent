<x-filament-widgets::widget>
    <div
        @if ($pollingInterval = $this->getPollingInterval())
            wire:poll.{{ $pollingInterval }}
        @endif
    >
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-6">

            @php
                $y = $yesterday ?? [];
                $w = $week ?? [];

                $toneTo = function ($tone) {
                    return [
                        'border' => match ($tone) {
                            'pos' => 'border-emerald-200',
                            'neg' => 'border-rose-200',
                            default => 'border-slate-200',
                        },
                        'bg' => match ($tone) {
                            'pos' => 'bg-emerald-50/70',
                            'neg' => 'bg-rose-50/70',
                            default => 'bg-white',
                        },
                        'val' => match ($tone) {
                            'pos' => 'text-emerald-800',
                            'neg' => 'text-rose-800',
                            default => 'text-slate-900',
                        },
                        'tag' => match ($tone) {
                            'pos' => 'bg-emerald-100 text-emerald-700',
                            'neg' => 'bg-rose-100 text-rose-700',
                            default => 'bg-slate-100 text-slate-600',
                        },
                    ];
                };

                $yT = $toneTo($y['tone'] ?? 'neutral');
                $wT = $toneTo($w['tone'] ?? 'neutral');

                $fmtPts = fn ($v) => ($v > 0 ? '+' : '') . number_format((float) $v, 2);
            @endphp

            <article class="hidden rounded-xl border p-3 shadow-sm lg:col-span-3 {{ $yT['border'] }} {{ $yT['bg'] }}">
                <div class="flex items-center justify-between">
                    <p class="text-xs font-medium text-slate-500">Yesterday (UTC)</p>
                    <span class="rounded-full px-2 py-0.5 text-xs {{ $yT['tag'] }}">YDAY</span>
                </div>

                <div class="mt-1 flex flex-wrap items-start gap-6 bg-slate-50 p-2">
                    <div>
                        <p class="text-xs text-slate-500">Net (pts)</p>
                        <p class="mt-1 text-lg font-semibold {{ $yT['val'] }}">{{ $fmtPts($y['net'] ?? 0) }}</p>
                    </div>

                    <div>
                        <p class="text-xs text-slate-500">ProfitFactor (pts)</p>
                        <p class="mt-1 text-lg font-semibold text-slate-900">
                            {{ ($y['pf'] ?? null) === null ? '—' : number_format((float) $y['pf'], 2) }}
                        </p>
                    </div>

                    <div>
                        <p class="text-xs text-slate-500">Closed trades</p>
                        <p class="mt-1 text-lg font-semibold text-slate-900">{{ (int) ($y['count'] ?? 0) }}</p>
                    </div>

                    <div>
                        <p class="text-xs text-slate-500">Win rate</p>
                        <p class="mt-1 text-lg font-semibold text-slate-900">
                            {{ ($y['win_rate'] ?? null) === null ? '—' : number_format((float) $y['win_rate'], 2) . '%' }}
                        </p>
                    </div>
                </div>
            </article>

            <article class="hidden rounded-xl border p-3 shadow-sm lg:col-span-3 {{ $wT['border'] }} {{ $wT['bg'] }}">
                <div class="flex items-center justify-between">
                    <p class="text-xs font-medium text-slate-500">This week (UTC)</p>
                    <span class="rounded-full px-2 py-0.5 text-xs {{ $wT['tag'] }}">WTD</span>
                </div>

                <div class="mt-1 flex flex-wrap items-start gap-6 bg-slate-50 p-2">
                    <div>
                        <p class="text-xs text-slate-500">Net (pts)</p>
                        <p class="mt-1 text-lg font-semibold {{ $wT['val'] }}">{{ $fmtPts($w['net'] ?? 0) }}</p>
                    </div>

                    <div>
                        <p class="text-xs text-slate-500">ProfitFactor (pts)</p>
                        <p class="mt-1 text-lg font-semibold text-slate-900">
                            {{ ($w['pf'] ?? null) === null ? '—' : number_format((float) $w['pf'], 2) }}
                        </p>
                    </div>

                    <div>
                        <p class="text-xs text-slate-500">Closed trades</p>
                        <p class="mt-1 text-lg font-semibold text-slate-900">{{ (int) ($w['count'] ?? 0) }}</p>
                    </div>

                    <div>
                        <p class="text-xs text-slate-500">Win rate</p>
                        <p class="mt-1 text-lg font-semibold text-slate-900">
                            {{ ($w['win_rate'] ?? null) === null ? '—' : number_format((float) $w['win_rate'], 2) . '%' }}
                        </p>
                    </div>
                </div>
            </article>

            <h3 class="text-lg font-semibold mt-4 col-span-full hidden">Today datalist</h3>

            @foreach (($cards ?? []) as $card)
                @php
                    $tone = $card['tone'] ?? 'neutral';

                    $toneBorder = match ($tone) {
                        'pos' => 'border-emerald-200',
                        'neg' => 'border-rose-200',
                        default => 'border-slate-200',
                    };

                    $toneBg = match ($tone) {
                        'pos' => 'bg-emerald-50/70',
                        'neg' => 'bg-rose-50/70',
                        default => 'bg-white',
                    };

                    $valueColor = match ($tone) {
                        'pos' => 'text-emerald-800',
                        'neg' => 'text-rose-800',
                        default => 'text-slate-900',
                    };

                    $tagBg = match ($tone) {
                        'pos' => 'bg-emerald-100 text-emerald-700',
                        'neg' => 'bg-rose-100 text-rose-700',
                        default => 'bg-slate-100 text-slate-600',
                    };
                @endphp

                <article class="rounded-xl border {{ $toneBorder }} {{ $toneBg }} p-3 shadow-sm">
                    <div class="flex items-center justify-between">
                        <p class="text-xs font-medium text-slate-500">
                            {{ $card['label'] ?? '' }}
                        </p>

                        <span class="rounded-full px-2 py-0.5 text-xs {{ $tagBg }}">
                            {{ $card['tag'] ?? '' }}
                        </span>
                    </div>

                    <p class="mt-2 text-lg font-semibold {{ $valueColor }}">
                        {{ $card['value'] ?? '' }}
                    </p>

                    <p class="mt-1 text-xs text-slate-500">
                        {{ $card['secondary'] ?? '' }}
                    </p>
                </article>
            @endforeach

        </div>
    </div>
</x-filament-widgets::widget>
