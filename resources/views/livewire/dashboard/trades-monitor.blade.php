<div>
    <h3 class="text-lg font-semibold mb-4">Trade monitor (symbol × timeframe)</h3>


{{--    <div class="mb-4 p-3 bg-gray-100 dark:bg-gray-800 rounded">
        <p class="text-sm text-gray-600 dark:text-gray-300">Debug: TradeMonitor count: {{ $this->debug_total_records }}</p>
        <p class="text-sm text-gray-600 dark:text-gray-300">Debug: Table query records count: {{ $this->debug_table_records_count }}</p>
        <p class="text-sm text-gray-600 dark:text-gray-300">Debug: Table records method count: {{ $this->getTableRecords()->count() }}</p>
    </div>--}}

    {{ $this->table }}


    <!-- FX Sync Mode Cards -->
    <div class="flex flex-wrap gap-3 my-10">
        @foreach($fx_mode_cards as $card)
            @php
                $modeColor = $card['mode'] === 'FAST' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-600';
                $reasonColor = match ($card['reason']) {
                    'session' => 'bg-blue-100 text-blue-800',
                    'open_trade' => 'bg-yellow-100 text-yellow-800',
                    default => 'bg-gray-100 text-gray-600',
                };

                $modeChangesIn = $card['mode_changes_in_seconds'];
                $countdownText = null;
                $countdownColor = 'text-gray-600';

                if ($modeChangesIn !== null) {
                    $hours = floor($modeChangesIn / 3600);
                    $minutes = floor(($modeChangesIn % 3600) / 60);
                    $countdownText = sprintf('%02d:%02d', $hours, $minutes);
                    $countdownColor = $hours > 24 ? 'text-blue-600' : 'text-gray-600';
                } elseif ($card['mode'] === 'FAST' && $card['reason'] === 'open_trade') {
                    $countdownText = 'after trade closes';
                    $countdownColor = 'text-yellow-600';
                }
            @endphp

            <div class="px-3 py-2 rounded-xl text-xs font-semibold w-[135px] {{ $modeColor }}">
                <div class="font-bold">{{ $card['symbol_code'] }}</div>

                <div class="mt-1 text-[11px]">
                    Mode: {{ $card['mode'] }} ({{ $card['interval_minutes'] }}m)
                </div>

                <div class="mt-1 text-[11px] {{ $reasonColor }}">
                    @if(!empty($card['active_sessions']))

                        {{ collect($card['active_sessions'])
                            ->map(fn($s) => ucfirst($s))
                            ->join(', ') }}
                    @elseif($card['reason'] === 'open_trade')
                        Reason: open trade
                    @else
                        Reason: idle
                    @endif
                </div>

                <div class="mt-1 text-[11px] {{ $countdownColor }}">
                    @if($countdownText)
                        Mode changes in: {{ $countdownText }}
                    @else
                        Mode changes: —
                    @endif
                </div>
            </div>
        @endforeach
    </div>
</div>
