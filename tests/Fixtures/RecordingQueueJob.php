<?php

namespace Tests\Fixtures;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RecordingQueueJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private string $outputPath;

    private string $label;

    public function __construct(string $outputPath, string $label)
    {
        $this->outputPath = $outputPath;
        $this->label = $label;
    }

    public function handle(): void
    {
        file_put_contents(
            $this->outputPath,
            $this->label.'|'.getmypid().PHP_EOL,
            FILE_APPEND | LOCK_EX,
        );
    }
}
