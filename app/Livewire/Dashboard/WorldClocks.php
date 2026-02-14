<?php

namespace App\Livewire\Dashboard;

use Carbon\CarbonInterface;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class WorldClocks extends Component
{
    public function render(): View
    {
        $clocks = [
            [
                'key' => 'utc',
                'label' => 'UTC',
                'tz' => 'UTC',
                'open' => null,
                'close' => null,
            ],
            [
                'key' => 'london',
                'label' => 'London',
                'tz' => 'Europe/London',
                'open' => '08:00',
                'close' => '17:00',
            ],
            [
                'key' => 'newyork',
                'label' => 'New York',
                'tz' => 'America/New_York',
                'open' => '08:00',
                'close' => '17:00',
            ],
            [
                'key' => 'tokyo',
                'label' => 'Tokyo',
                'tz' => 'Asia/Tokyo',
                'open' => '09:00',
                'close' => '18:00',
            ],
            [
                'key' => 'sydney',
                'label' => 'Sydney',
                'tz' => 'Australia/Sydney',
                'open' => '08:00',
                'close' => '17:00',
            ],
        ];

        $out = [];

        foreach ($clocks as $c) {
            $now = now($c['tz']);

            $hour = (int) $now->format('G');
            $minute = (int) $now->format('i');

            $hourAngle = (($hour % 12) * 30) + ($minute * 0.5);
            $minuteAngle = $minute * 6;

            $isOpen = false;
            if (is_string($c['open']) && is_string($c['close'])) {
                $isOpen = $this->isSessionOpen($now, $c['open'], $c['close']);
            }

            $out[] = [
                'key' => (string) $c['key'],
                'label' => (string) $c['label'],
                'is_open' => $isOpen,
                'hour_angle' => $hourAngle,
                'minute_angle' => $minuteAngle,
            ];
        }

        return view('livewire.dashboard.world-clocks', [
            'clocks' => $out,
        ]);
    }

    private function isSessionOpen(CarbonInterface $now, string $openTime, string $closeTime): bool
    {
        $open = $now->copy()->setTimeFromTimeString($openTime);
        $close = $now->copy()->setTimeFromTimeString($closeTime);

        if ($close->lessThanOrEqualTo($open)) {
            $close = $close->addDay();
        }

        return $now->between($open, $close, true);
    }
}
