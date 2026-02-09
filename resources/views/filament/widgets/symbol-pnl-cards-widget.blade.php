<div class="space-y-4">
    <!-- Portfolio Card -->
    <div class="bg-white rounded-lg shadow p-4">
        <h3 class="text-lg font-semibold mb-3">Portfolio</h3>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="bg-gray-50 p-3 rounded">
                <div class="text-sm text-gray-600 mb-1">Open P&L (pts)</div>
                <x-filament::badge :color="$portfolio_open_color">
                    {{ $portfolio_open_total > 0 ? '+' : '' }}{{ number_format($portfolio_open_total, 2) }}
                </x-filament::badge>
            </div>
            <div class="bg-gray-50 p-3 rounded">
                <div class="text-sm text-gray-600 mb-1">Closed today (pts)</div>
                <x-filament::badge :color="$portfolio_closed_today_color">
                    {{ $portfolio_closed_today_total > 0 ? '+' : '' }}{{ number_format($portfolio_closed_today_total, 2) }}
                </x-filament::badge>
            </div>
        </div>
    </div>

    <!-- Symbol Cards -->
    <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
        @foreach($symbols as $symbol)
            <div class="bg-white rounded-lg shadow p-4">
                <div class="flex justify-between items-center mb-3">
                    <h4 class="text-lg font-semibold">{{ $symbol['symbol'] }}</h4>
                </div>

                <div class="space-y-2 mb-3">
                    <x-filament::badge :color="$symbol['symbol_color']">
                        Open (pts): {{ $symbol['open_total'] > 0 ? '+' : '' }}{{ number_format($symbol['open_total'], 2) }}
                    </x-filament::badge>

                    <x-filament::badge :color="$symbol['symbol_color']">
                        Closed today (pts): {{ $symbol['closed_today_total'] > 0 ? '+' : '' }}{{ number_format($symbol['closed_today_total'], 2) }}
                    </x-filament::badge>
                </div>

                @if(!empty($symbol['open_by_tf']))
                    <div class="text-xs text-gray-500 mb-2">
                        Open by TF:
                        @foreach($symbol['open_by_tf'] as $tf => $value)
                            @if($value != 0)
                                <span class="mr-1">{{ $tf }}:{{ $value > 0 ? '+' : '' }}{{ number_format($value, 2) }}</span>
                            @endif
                        @endforeach
                    </div>
                @endif

                @if(!empty($symbol['closed_today_by_tf']))
                    <div class="text-xs text-gray-500">
                        Closed today by TF:
                        @foreach($symbol['closed_today_by_tf'] as $tf => $value)
                            @if($value != 0)
                                <span class="mr-1">{{ $tf }}:{{ $value > 0 ? '+' : '' }}{{ number_format($value, 2) }}</span>
                            @endif
                        @endforeach
                    </div>
                @endif
            </div>
        @endforeach
    </div>
</div>
