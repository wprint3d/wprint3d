<?php

namespace App\Events;

use App\Models\Printer;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;

use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

use Illuminate\Foundation\Events\Dispatchable;

class PrinterConnectionStatusUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public $queue = 'broadcasts';

    public string $printerId;
    public array  $statistics;
    public mixed  $lastSeen;

    public function __construct(string $printerId, ?int $lastSeen = null)
    {
        $this->printerId  = $printerId;
        $this->statistics = Printer::getStatisticsOf( $printerId );
        $this->lastSeen   =
            $lastSeen
                ? $lastSeen
                : Printer::getLastSeenOf( $printerId );
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return \Illuminate\Broadcasting\Channel|array
     */
    public function broadcastOn()
    {
        return new PrivateChannel('connection-status.' . $this->printerId);
    }
}
