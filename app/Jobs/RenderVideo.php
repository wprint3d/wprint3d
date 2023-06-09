<?php

namespace App\Jobs;

use App\Events\RecordingRenderFinished;
use App\Events\RecordingRenderProgress;

use App\Models\Configuration;
use App\Models\User;

use Illuminate\Bus\Queueable;

use Illuminate\Contracts\Queue\ShouldQueue;

use Illuminate\Foundation\Bus\Dispatchable;

use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

use FFMpeg\FFMpeg;

use FFMpeg\Coordinate\Dimension;
use FFMpeg\Coordinate\TimeCode;

use FFMpeg\Format\Video\WebM;

class RenderVideo implements ShouldQueue
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

    private User   $owner;
    private int    $index;
    private bool   $requiresLibCamera;
    private string $fileName;
    private string $jobUID;

    const RECORDINGS_DIRECTORY = 'recordings';
    const LOG_CHANNEL          = 'video-renderer';

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(User $owner, int $index, bool $requiresLibCamera, string $fileName, string $jobUID)
    {
        $this->queue = 'recordings';

        $this->owner             = $owner;
        $this->index             = $index;
        $this->requiresLibCamera = $requiresLibCamera;
        $this->fileName          = $fileName;
        $this->jobUID            = $jobUID;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $log = Log::channel( self::LOG_CHANNEL );

        $recordingsPath   = Storage::path( self::RECORDINGS_DIRECTORY );
        $recorderSettings = $this->owner->settings['recording'];

        list($videoWidth, $videoHeight) = explode('x', $recorderSettings['resolution']);

        $targetFile =
            $recordingsPath
            . '/' .
            basename($this->fileName)
            . '_' .
            $this->jobUID
            . '_' .
            $this->index
            . '_' .
            ($this->requiresLibCamera ? '1' : '0')
            . '.webm';

        $ffmpeg = FFMpeg::create([ 'timeout' => null ]);

        $format = new WebM();
        $format->on('progress', function ($video, $format, $percentage) use ($log) {
            $log->debug("{$this->fileName}: {$percentage}% completed");

            RecordingRenderProgress::dispatch(
                $this->owner->activePrinter, // printerId
                $this->fileName,             // fileName
                $percentage                  // progress
            );
        })->setAdditionalParameters([
            '-framerate', $recorderSettings['framerate'],
            '-r',         $recorderSettings['framerate']
        ]);

        $video = $ffmpeg->open(
            Storage::path(
                getSnapshotsPrefix(
                    fileName: $this->fileName,
                    jobUID:   $this->jobUID,
                    index:    $this->index,
                    requiresLibCamera: $this->requiresLibCamera
                ) . '_%d.jpg'
            )
        );

        $video->filters()->resize(new Dimension( $videoWidth, $videoHeight ));
        $video->save( $format, $targetFile ); // render the video

        // Generate a thumbnail
        $renderedFile = $ffmpeg->open( $targetFile );

        $thumbnailFrameSecs = $renderedFile->getFormat()->get('duration', 0);

        if ($thumbnailFrameSecs > 0) {
            $thumbnailFrameSecs /= 2;
        }

        $renderedFile
            ->frame( TimeCode::fromSeconds( $thumbnailFrameSecs ) )
            ->save(
                $recordingsPath
                . '/' .
                basename($this->fileName)
                . '_' .
                $this->jobUID
                . '_' .
                $this->index
                . '_' .
                ($this->requiresLibCamera ? '1' : '0')
                . '.jpg'
            );

        // Remove origin files
        foreach (Storage::files( SaveSnapshot::SNAPSHOTS_DIRECTORY ) as $file) {
            if (
                str_starts_with(
                    haystack: $file,
                    needle:   getSnapshotsPrefix(
                        fileName: $this->fileName,
                        jobUID:   $this->jobUID,
                        index:    $this->index,
                        requiresLibCamera: $this->requiresLibCamera
                    )
                )
                &&
                str_ends_with(
                    haystack: $file,
                    needle:   '.jpg'
                )
            ) { Storage::delete( $file ); }
        }

        // Allow some time to allow the delete button to re-enable
        sleep( Configuration::get('renderFileBlockingSecs') + 1 );

        RecordingRenderFinished::dispatch( $this->owner->activePrinter );
    }
}
