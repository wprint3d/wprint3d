<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;

use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Facades\Auth;

/**
 * SystemMessage
 * 
 * A private event sent to a specific user across all of their active sessions.
 */
class SystemMessage implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public $queue = 'broadcasts';

    public string $userId;
    public string $name;
    public mixed  $detail = null;

    /**
     * Create a new event instance.
     *
     * @return void
     */
    public function __construct(string $userId, string $name, mixed $detail = null)
    {
        $this->userId   = $userId;
        $this->name     = $name;
        $this->detail   = $detail;
    }

    public static function send(string $name, mixed $detail = null) {
        self::dispatch(
            Auth::id(), // userId
            $name,      // name
            $detail     // detail
        );
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return \Illuminate\Broadcasting\Channel|array
     */
    public function broadcastOn()
    {
        return new PrivateChannel("system-message.{$this->userId}");
    }
}
