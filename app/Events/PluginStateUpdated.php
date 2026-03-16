<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

class PluginStateUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public $queue = 'broadcasts';

    public function __construct(
        public string $pluginId,
        public array $state,
        public string $updatedAt,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('plugins');
    }
}
