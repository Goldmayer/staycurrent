<div class="space-y-6" wire:poll.15s>

    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-6 justify-items-center">
        @foreach($clocks as $clock)
            <div class="flex flex-col items-center space-y-2">
                <!-- Clock Container -->
                <div class="relative">
                    <!-- Outer Ring (Session Highlight) -->
                    <div class="absolute inset-0 rounded-full border-4 transition-colors duration-300 {{ $clock['label'] === 'UTC' ? 'border-gray-300' : ($clock['is_open'] ? 'border-green-500' : 'border-gray-300') }}"></div>


                    <!-- SVG Clock -->
                    <svg class="w-14 h-14" viewBox="0 0 100 100">
                        <!-- Clock Face -->
                        <circle cx="50" cy="50" r="48" fill="#ffffff" stroke="#e5e7eb" stroke-width="2"/>

                        <!-- Hour Markers -->
                        @for($i = 1; $i <= 12; $i++)
                            @php
                                $angle = $i * 30;
                                $x1 = 50 + 40 * cos(deg2rad($angle - 90));
                                $y1 = 50 + 40 * sin(deg2rad($angle - 90));
                                $x2 = 50 + 35 * cos(deg2rad($angle - 90));
                                $y2 = 50 + 35 * sin(deg2rad($angle - 90));
                            @endphp
                            <line x1="{{ $x1 }}" y1="{{ $y1 }}" x2="{{ $x2 }}" y2="{{ $y2 }}"
                                  stroke="#9ca3af" stroke-width="2" stroke-linecap="round"/>
                        @endfor

                        <!-- Minute Markers -->
                        @for($i = 0; $i < 60; $i++)
                            @if($i % 5 !== 0)
                                @php
                                    $angle = $i * 6;
                                    $x1 = 50 + 42 * cos(deg2rad($angle - 90));
                                    $y1 = 50 + 42 * sin(deg2rad($angle - 90));
                                    $x2 = 50 + 40 * cos(deg2rad($angle - 90));
                                    $y2 = 50 + 40 * sin(deg2rad($angle - 90));
                                @endphp
                                <line x1="{{ $x1 }}" y1="{{ $y1 }}" x2="{{ $x2 }}" y2="{{ $y2 }}"
                                      stroke="#e5e7eb" stroke-width="1"/>
                            @endif
                        @endfor

                        <!-- Hour Hand -->
                        <line x1="50" y1="50"
                              x2="{{ 50 + 25 * cos(deg2rad($clock['hour_angle'] - 90)) }}"
                              y2="{{ 50 + 25 * sin(deg2rad($clock['hour_angle'] - 90)) }}"
                              stroke="#374151" stroke-width="4" stroke-linecap="round"
                              class="transition-transform duration-1000 ease-in-out"
                              style="transform: rotate({{ $clock['hour_angle'] }}deg); transform-origin: 50px 50px;"/>

                        <!-- Minute Hand -->
                        <line x1="50" y1="50"
                              x2="{{ 50 + 35 * cos(deg2rad($clock['minute_angle'] - 90)) }}"
                              y2="{{ 50 + 35 * sin(deg2rad($clock['minute_angle'] - 90)) }}"
                              stroke="#6b7280" stroke-width="3" stroke-linecap="round"
                              class="transition-transform duration-1000 ease-in-out"
                              style="transform: rotate({{ $clock['minute_angle'] }}deg); transform-origin: 50px 50px;"/>

                        <!-- Center Dot -->
                        <circle cx="50" cy="50" r="3" fill="#374151"/>
                    </svg>
                </div>

                <!-- City Label -->
                <div class="text-sm font-medium text-gray-700">
                    {{ $clock['label'] }}
                </div>
            </div>
        @endforeach
    </div>
</div>
