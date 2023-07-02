<?php

namespace App\Jobs;

use App\Events\PreviewBuffered;
use App\Events\PreviewLayerMapReady;
use App\Events\PreviewLineReady;

use App\Exceptions\InitializationException;

use App\Models\Configuration;
use App\Models\Printer;

use Illuminate\Bus\Queueable;

use Illuminate\Contracts\Queue\ShouldQueue;

use Illuminate\Foundation\Bus\Dispatchable;

use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class SendLinesToClientPreview implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 1;

    /**
     * Indicate if the job should be marked as failed on timeout.
     *
     * @var bool
     */
    public $failOnTimeout = false;
    
    private Printer $printer;

    private string $previewUID;
    private int    $currentLine;
    private int    $streamMaxLengthBytes;
    private bool   $shouldMapLayers;
    private int    $lineNumber;
    private int    $streamBufferMaxLines;

    const STREAM_BUFFER_CHUNK_MAX_MEGABYTES = 0.5;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(string $previewUID, string $printerId, int $currentLine, bool $shouldMapLayers)
    {
        $this->queue = 'previews';

        $this->printer = Printer::select('activeFile')->find( $printerId );

        $this->previewUID           = $previewUID; 
        $this->currentLine          = $currentLine;
        $this->streamMaxLengthBytes = Configuration::get('streamMaxLengthBytes');
        $this->shouldMapLayers      = $shouldMapLayers;
        $this->lineNumber           = 0;
        $this->streamBufferMaxLines = (
            (self::STREAM_BUFFER_CHUNK_MAX_MEGABYTES * 1024 * 1024) // N MB to bytes
            /
            $this->streamMaxLengthBytes                             // maxiumum number of bytes in a single line of G-code
        ); // lines
    }

    private function mapLayers(mixed &$gcode): void {
        $lineNumber = 0;
        $layerMap   = [];

        // map layers to line numbers
        while (
            $line = readStreamLine(
                stream:    $gcode,
                maxLength: $this->streamMaxLengthBytes
            )
        ) {
            $line = getGCode( $line );

            if (!$line) continue;

            if (str_starts_with($line, 'G0') || str_starts_with($line, 'G1')) {
                $parts = explode(' ', $line);

                foreach ($parts as $part) {
                    if (str_starts_with($part, 'Z')) {
                        $layerMap[] = $lineNumber;
                    }
                }
            }

            $lineNumber++;
        }

        PreviewLayerMapReady::dispatch(
            $this->previewUID,   // previewUID
            $this->printer->_id, // printerId
            $layerMap            // layerMap
        );
    }

    private function parseLines(mixed &$gcode): void {
        $lineNumber  = 0;
        $linesBuffer = [];

        // send lines to client up to $currentLine
        while (
            $line = readStreamLine(
                stream:    $gcode,
                maxLength: $this->streamMaxLengthBytes
            )
        ) {
            $line = getGCode( $line );

            if (!$line) continue;

            if (count($linesBuffer) > $this->streamBufferMaxLines) {
                PreviewLineReady::dispatch(
                    $this->previewUID,                       // previewUID
                    $this->printer->_id,                     // printerId
                    implode(PHP_EOL, $linesBuffer),          // command
                    $lineNumber,                             // line
                    ($lineNumber * 100) / $this->currentLine // percentage                
                );

                $linesBuffer = [];
            }

            $linesBuffer[] = $line;

            if ($lineNumber == $this->currentLine) break;

            $lineNumber++;
        }

        $percentage =
            $this->currentLine
                ? ($lineNumber * 100) / $this->currentLine
                : 100;

        if ($linesBuffer) {
            PreviewLineReady::dispatch(
                $this->previewUID,              // previewUID
                $this->printer->_id,            // printerId
                implode(PHP_EOL, $linesBuffer), // command
                $lineNumber,                    // line
                $percentage                     // percentage                
            );
        }

        PreviewBuffered::dispatch(
            $this->previewUID,   // previewUID
            $this->printer->_id  // printerId
        );
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $filePath = $this->printer->activeFile;

        $gcode = Storage::getDriver()->readStream( $filePath );

        if (!$gcode) {
            throw new InitializationException("failed to open {$filePath}.");
        }

        $this->mapLayers( $gcode );

        // back to line 0
        rewind( $gcode );

        $this->parseLines( $gcode );
    }
}
