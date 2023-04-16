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

    public function __construct(string $printerId)
    {
        $this->printerId  = $printerId;
        $this->statistics = Printer::getStatisticsOf( $printerId );
        $this->lastSeen   = Printer::getLastSeenOf( $printerId );

        if ($this->lastSeen) {
            $this->lastSeen = $this->lastSeen->toDateTime()->getTimestamp();
        }
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
