<?php

namespace App\Jobs;

use App\Events\RecordingRenderFinished;
use App\Events\RecordingRenderProgress;

use App\Models\User;
use App\Models\Video;

use Illuminate\Bus\Queueable;

use Illuminate\Contracts\Queue\ShouldQueue;

use Illuminate\Foundation\Bus\Dispatchable;

use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

use Illuminate\Support\Str;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

use FFMpeg\FFMpeg;

use FFMpeg\Coordinate\Dimension;
use FFMpeg\Coordinate\TimeCode;

use FFMpeg\Filters\Video\ResizeFilter;

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
    private string $printerId;

    const RECORDINGS_DIRECTORY = 'recordings';
    const LOG_CHANNEL          = 'video-renderer';

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(User $owner, int $index, bool $requiresLibCamera, string $fileName, string $jobUID, string $printerId)
    {
        $this->queue = 'recordings';

        $this->owner             = $owner;
        $this->index             = $index;
        $this->requiresLibCamera = $requiresLibCamera;
        $this->fileName          = $fileName;
        $this->jobUID            = $jobUID;
        $this->printerId         = $printerId;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $log = Log::channel( self::LOG_CHANNEL );

        $recordingsDisk   = Storage::disk('recordings');

        $recorderSettings = $this->owner->settings['recording'];

        list($videoWidth, $videoHeight) = explode('x', $recorderSettings['resolution']);

        $targetFileName = (
            basename($this->fileName)
            . '_' .
            $this->jobUID
            . '_' .
            $this->index
            . '_' .
            ($this->requiresLibCamera ? '1' : '0')
            . '.webm'
        );

        $instance = new Video();
        $instance->fileName    = $targetFileName;
        $instance->jobUID      = $this->jobUID;
        $instance->isComplete  = false;
        $instance->printer()->associate($this->owner->getActivePrinter());
        $instance->owner()->associate($this->owner);
        $instance->save();

        $targetFilePath = $recordingsDisk->path($targetFileName);

        $ffmpeg = FFMpeg::create([ 'timeout' => null ]);

        $format = new WebM();
        $format->on('progress', function ($video, $format, $percentage) use ($log) {
            $log->debug("{$this->fileName}: {$percentage}% completed");

            RecordingRenderProgress::dispatch(
                $this->printerId, // printerId
                $this->fileName,  // fileName
                $percentage       // progress
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

        $video->filters()->resize(
            new Dimension( $videoWidth, $videoHeight ),
            ResizeFilter::RESIZEMODE_INSET
        );

        // Render the video
        $video->save(
            format:         $format,
            outputPathfile: $targetFilePath
        );

        // Generate a thumbnail
        $renderedFile = $ffmpeg->open($targetFilePath);

        $thumbnailFrameSecs = 0;
        $videoDurationSecs  = $renderedFile->getFormat()->get('duration', 0);

        if ($videoDurationSecs > 0) {
            $thumbnailFrameSecs = $videoDurationSecs / 2;
        }

        $targetThumbnailFileName = Str::replaceLast('.webm', '.jpg', $targetFileName);

        // Save the thumbnail
        $renderedFile
            ->frame( TimeCode::fromSeconds($thumbnailFrameSecs) )
            ->save( $recordingsDisk->path($targetThumbnailFileName) );

        $instance->duration   = $videoDurationSecs;
        $instance->thumbnail  = $targetThumbnailFileName;
        $instance->isComplete = true;
        $instance->save();

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

        RecordingRenderFinished::dispatch( $this->printerId );
    }
}
