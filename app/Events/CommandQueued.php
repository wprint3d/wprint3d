<?php

namespace App\Events;

use Illuminate\Contracts\Queue\ShouldQueue;

use Illuminate\Foundation\Events\Dispatchable;

class CommandQueued implements ShouldQueue
{
    use Dispatchable;

    public $queue = 'control';

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
