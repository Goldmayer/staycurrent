<?php

namespace App\Services\Notifications;

use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Notifications\DatabaseNotification;

class SignalNotificationService
{
    public function notify(array $payload, ?User $user = null): void
    {
        $user = $user ?? User::query()->orderBy('id')->first();

        if (! $user) {
            return;
        }

        $title = (string) ($payload['title'] ?? 'Notification');
        $body = (string) ($payload['message'] ?? '');
        $level = (string) ($payload['level'] ?? 'info');

        if ($title === '' && $body === '') {
            return;
        }

        $exists = DatabaseNotification::query()
                                      ->where('notifiable_type', User::class)
                                      ->where('notifiable_id', $user->id)
                                      ->where('data->title', $title)
                                      ->where('data->body', $body)
                                      ->exists();

        if ($exists) {
            return;
        }

        $notification = Notification::make()
                                    ->title($title)
                                    ->body($body);

        match ($level) {
            'success' => $notification->success(),
            'warning' => $notification->warning(),
            'danger', 'error' => $notification->danger(),
            default => $notification->info(),
        };

        $notification->send();

        $notification->sendToDatabase($user, [
            'title' => $title,
            'body' => $body,
            'level' => $level,
        ]);
    }
}
