<?php

namespace App\Events;

use App\Models\Printer;

use Exception;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;

use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

use Illuminate\Foundation\Events\Dispatchable;

use Illuminate\Queue\SerializesModels;

class PrinterConnectionStatusUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $queue = 'broadcasts';

    public string   $printerId;
    public string   $lastSeen;
    public array    $statistics;

    public function __construct(string $printerId)
    {
        $printer = Printer::select('lastSeen')->find( $printerId );

        if (!$printer) throw new Exception('No such printer.');

        $this->printerId    = $printer->_id;
        $this->lastSeen     = $printer->lastSeen->toDateTime()->getTimestamp();
        $this->statistics   = $printer->getStatistics();
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
