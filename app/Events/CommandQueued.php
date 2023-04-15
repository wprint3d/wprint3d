<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;

use Illuminate\Contracts\Queue\ShouldQueue;

use Illuminate\Foundation\Events\Dispatchable;

use Illuminate\Queue\SerializesModels;

class CommandQueued implements ShouldQueue
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $printerId;

    /**
     * Create a new event instance.
     *
     * @return void
     */
    public function __construct(string $printerId)
    {
        $this->printerId = $printerId;
    }
}
