<?php

namespace App\Jobs;

use App\Plugins\Contracts\PluginManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PreparePluginRuntime implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 1800;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 3600;

    public function __construct(
        public string $pluginId,
        public string $expectedVersion,
    ) {}

    public function uniqueId(): string
    {
        return $this->pluginId.':'.$this->expectedVersion;
    }

    public function handle(PluginManager $pluginManager): void
    {
        $pluginManager->prepareRuntime($this->pluginId, $this->expectedVersion);
    }
}
