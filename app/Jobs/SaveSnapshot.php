<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;

use Illuminate\Contracts\Queue\ShouldQueue;

use Illuminate\Foundation\Bus\Dispatchable;

use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

use Illuminate\Queue\Middleware\WithoutOverlapping;

use Exception;

class SaveSnapshot implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 3;

    /**
     * Indicate if the job should be marked as failed on timeout.
     *
     * @var bool
     */
    public $failOnTimeout = false;

    private int    $index;
    private bool   $requiresLibCamera;
    private string $url;
    private string $fileName;
    private string $jobUID;
    private ?int   $expiresAt = null;

    const SNAPSHOTS_DIRECTORY = 'snapshots';

    /**
     * Get the middleware the job should pass through.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        // Prevents overlappings jobs from being retried for, at least, 60s.
        return [(new WithoutOverlapping($this->url))->dontRelease()->expireAfter(60)];
    }

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(int $index, bool $requiresLibCamera, string $url, string $fileName, string $jobUID, int $expectedIntervalSecs)
    {
        $this->queue = 'snapshots';

        $this->index             = $index;
        $this->requiresLibCamera = $requiresLibCamera;
        $this->url               = 'https://proxy' . $url;
        $this->fileName          = $fileName;
        $this->jobUID            = $jobUID;
        $this->expiresAt         = now()->addSeconds($expectedIntervalSecs * 2)->getTimestamp(); // frameskips if it takes too long
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        if (!$this->expiresAt) {
            Log::warning(__METHOD__ . ": no expiration date set, this is a legacy job. - URL = {$this->url}, FILENAME = {$this->fileName}");

            return;
        }

        if (time() > $this->expiresAt) {
            Log::warning(__METHOD__ . ": frameskip: this job took too long to process. - URL = {$this->url}, FILENAME = {$this->fileName}");

            return;
        }

        $response = Http::withoutVerifying()->get( $this->url );

        if (!$response->successful()) {
            throw new Exception("{$this->url}: couldn't take screenshot: " . $response->body());
        }

        $pathPrefix = getSnapshotsPrefix(
            fileName: $this->fileName,
            jobUID:   $this->jobUID,
            index:    $this->index,
            requiresLibCamera: $this->requiresLibCamera
        );

        $incrementCacheKey = str_replace(
            search:     ' ',
            replace:    '+',
            subject:    basename( $pathPrefix )
        );

        Cache::add(
            key:    $incrementCacheKey,
            value:  0,
            ttl:    (7 * 24 * 60 * 60) // 1 week
        );

        $path = $pathPrefix . '_' . (Cache::increment( $incrementCacheKey )) . '.jpg';

        Storage::put(
            path:       $path,
            contents:   $response->body()
        );

        Log::debug(__METHOD__ . ': ' . $path);
    }
}
