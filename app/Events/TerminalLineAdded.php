<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;

use Illuminate\Contracts\Queue\ShouldQueue;

use Illuminate\Foundation\Events\Dispatchable;

class TerminalLineAdded implements ShouldQueue
{
    use Dispatchable, InteractsWithSockets;

    public string $printerId;
    public string $dateString;
    public string $line;

    /**
     * Create a new event instance.
     *
     * @return void
     */
    public function __construct(string $printerId, string $dateString, string $line)
    {
        $this->printerId  = $printerId;
        $this->dateString = $dateString;
        $this->line       = $line;
    }
}
