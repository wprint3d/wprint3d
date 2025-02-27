<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class SystemMessage extends Notification implements ShouldQueue
{
    use Queueable;

    private string $title       = 'Empty message';
    private string $description = '';

    /**
     * Create a new notification instance.
     */
    public function __construct(string $title, string $description)
    {
        $this->title       = $title;
        $this->description = $description;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['broadcast', 'database'];
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title'       => $this->title,
            'description' => $this->description,
        ];
    }

    /**
     * Get the broadcastable representation of the notification.
     */
    public function toBroadcast(object $notifiable): object
    {
        return new BroadcastMessage([
            'title'       => $this->title,
            'description' => $this->description,
            'created_at'  => now()->toIso8601String(),
        ]);
    }
}
