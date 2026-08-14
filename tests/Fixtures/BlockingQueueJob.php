<?php

namespace Tests\Fixtures;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class BlockingQueueJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 0;

    private string $startedPath;

    private string $releasePath;

    private string $finishedPath;

    public function __construct(string $startedPath, string $releasePath, string $finishedPath)
    {
        $this->startedPath = $startedPath;
        $this->releasePath = $releasePath;
        $this->finishedPath = $finishedPath;
    }

    public function handle(): void
    {
        file_put_contents($this->startedPath, (string) getmypid(), LOCK_EX);

        while (! file_exists($this->releasePath)) {
            usleep(50000);
        }

        file_put_contents($this->finishedPath, (string) getmypid(), LOCK_EX);
    }
}
