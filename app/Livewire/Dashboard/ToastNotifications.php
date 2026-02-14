<?php

namespace App\Livewire\Dashboard;

use Filament\Notifications\Notification;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;

class ToastNotifications extends Component
{
    public function check(): void
    {
        $user = Auth::user();

        if (! $user) {
            return;
        }

        $cacheKey = 'dash_toast_last_notification_id:user:' . $user->id;
        $lastId = (int) Cache::get($cacheKey, 0);

        $items = DatabaseNotification::query()
                                     ->where('notifiable_type', $user::class)
                                     ->where('notifiable_id', $user->id)
                                     ->where('id', '>', $lastId)
                                     ->orderBy('id')
                                     ->limit(10)
                                     ->get();

        if ($items->isEmpty()) {
            return;
        }

        $maxId = $lastId;

        foreach ($items as $n) {
            $title = (string) data_get($n->data, 'title', 'Notification');
            $body  = (string) data_get($n->data, 'body', '');

            if ($title === '' && $body === '') {
                $maxId = max($maxId, (int) $n->id);
                continue;
            }

            $toast = Notification::make()
                                 ->title($title)
                                 ->body($body);

            $level = (string) data_get($n->data, 'level', 'info');

            match ($level) {
                'success' => $toast->success(),
                'warning' => $toast->warning(),
                'danger', 'error' => $toast->danger(),
                default => $toast->info(),
            };

            $toast->send();

            $maxId = max($maxId, (int) $n->id);
        }

        Cache::forever($cacheKey, $maxId);
    }

    public function render()
    {
        return view('livewire.dashboard.toast-notifications');
    }
}
