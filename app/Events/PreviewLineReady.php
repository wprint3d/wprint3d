<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;

use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

use Illuminate\Foundation\Events\Dispatchable;

class PreviewLineReady implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public $queue = 'broadcasts';

    public string $previewUID;
    public string $printerId;
    public string $command;
    public int    $line;
    public float  $percentage;

    public function __construct(string $previewUID, string $printerId, string $command, int $line, float $percentage)
    {
        $this->previewUID = $previewUID;
        $this->printerId  = $printerId;
        $this->command    = $command;
        $this->line       = $line;
        $this->percentage = $percentage;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return \Illuminate\Broadcasting\Channel|array
     */
    public function broadcastOn()
    {
        return new PrivateChannel('preview.' . $this->printerId);
    }
}
