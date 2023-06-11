<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;

use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

use Illuminate\Foundation\Events\Dispatchable;

class ToastMessage implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public $queue = 'broadcasts';

    public string $userId;
    public int    $type;
    public string $message;

    /**
     * Create a new event instance.
     *
     * @return void
     */
    public function __construct(string $userId, int $type, string $message)
    {
        $this->userId    = $userId;
        $this->type      = $type;
        $this->message   = $message;
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
