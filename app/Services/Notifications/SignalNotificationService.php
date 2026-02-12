<?php

namespace App\Services\Notifications;

use App\Models\User;
use Filament\Notifications\Notification;

class SignalNotificationService
{
    public function notify(array $payload, ?User $user = null): void
    {
        $user = $user ?? User::query()->orderBy('id')->first();

        if (! $user) {
            return;
        }

        $notification = Notification::make()
                                    ->title((string) ($payload['title'] ?? 'Notification'))
                                    ->body((string) ($payload['message'] ?? ''));

        match ($payload['level'] ?? 'info') {
            'success' => $notification->success(),
            'warning' => $notification->warning(),
            'danger'  => $notification->danger(),
            default   => $notification->info(),
        };

        $notification->sendToDatabase($user);
    }
}
