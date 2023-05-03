<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;

use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

use Illuminate\Foundation\Events\Dispatchable;

class PreviewLayerMapReady implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public $queue = 'broadcasts';

    public string $previewUID;
    public string $printerId;
    public array  $layerMap;

    public function __construct(string $previewUID, string $printerId, array $layerMap)
    {
        $this->previewUID = $previewUID;
        $this->printerId  = $printerId;
        $this->layerMap   = $layerMap;
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
