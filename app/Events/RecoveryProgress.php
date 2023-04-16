<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;

use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

use Illuminate\Foundation\Events\Dispatchable;

class RecoveryProgress implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public $queue = 'broadcasts';

    public string $printerId;
    public int    $percentage;

    /**
     * Create a new event instance.
     *
     * @return void
     */
    public function __construct(string $printerId, int $percentage)
    {
        $this->printerId  = $printerId;
        $this->percentage = $percentage;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return \Illuminate\Broadcasting\Channel|array
     */
    public function broadcastOn()
    {
        return new PrivateChannel('recovery-progress.' . $this->printerId);
    }
}
