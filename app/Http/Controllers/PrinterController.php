<?php

namespace App\Http\Controllers;

use App\Enums\ControlDirection;
use App\Enums\FormatterCommands;
use App\Enums\RecoveryStage;
use App\Events\RecoveryProgress;
use App\Events\RecoveryStageChanged;
use App\Events\SystemMessage;
use App\Exceptions\InitializationException;

use App\Jobs\PrintGcode;
use App\Jobs\RenderVideo;
use App\Models\Camera;
use App\Models\Configuration;
use App\Models\Printer;
use App\Models\Video;
use Carbon\Carbon;
use Illuminate\Support\Str;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use MongoDB\BSON\ObjectId;
use Throwable;

class PrinterController extends Controller
{

    const RECOVERED_FILE_PREFIX = 'rec';

    const REC_E_AXIS_RETRACT_OFFSET   =  5;   // mm
    const REC_X_AXIS_HOLDING_OFFSET   = -1;   // mm
    const REC_Y_AXIS_HOLDING_OFFSET   = -1;   // mm
    const REC_Z_AXIS_HOLDING_OFFSET   =  1.5; // mm

    const REC_XY_AXIS_WITH_MIN_HOLDING_OFFSET = 1; // mm

    const REC_Z_PROBE_HOMING_OFFSET = 4.5; // mm

    private FilesystemAdapter $gcodeStorage;

    public function __construct() {
        $this->gcodeStorage = Storage::disk('gcode');
    }

    private function checkConnectivityOrFail(?Printer $printer): void {
        if (!$printer) {
            throw ValidationException::withMessages([ 'printer' => 'No such printer.' ]);
        }

        if (!$printer->connected) {
            throw ValidationException::withMessages([ 'printer' => 'Couldn\'t complete action: this printer is not connected.' ]);
        }
    }

    public function index(): Collection {
        return Printer::select(
            '_id',
            'connected',
            'node',
            'cameras',
            'machine.machineType',
            'machine.uuid',
            'machine.extruderCount',
            'machine.firmwareName',
            'machine.uuid'
        )->get()->map(function ($printer) {
            if ($printer->cameras ?? null) {
                $printer->mainCamera =
                    Camera::select('_id', 'url')
                          // ->where('connected', true)
                          ->whereRaw([
                            '_id' => [
                                '$in' => Arr::map($printer->cameras, fn($camera) => new ObjectId($camera))
                            ]
                          ])->first();
            }

            return $printer;
        });
    }

    public function get(string $printerId): Printer {
        return Printer::find($printerId);
    }

    public function delete(string $printerId): Response {
        $printer = Printer::find($printerId);

        if (!$printer) { return response('', Response::HTTP_NOT_FOUND); }

        if ($printer->activeFile) {
            throw ValidationException::withMessages([ 'printer' => 'Couldn\'t complete action: there\'s an active file.' ]);
        }

        if ($printer->connected) {
            throw ValidationException::withMessages([ 'printer' => 'Couldn\'t complete action: this printer is connected.' ]);
        }

        $printer->delete();

        return response('');
    }

    public function preheatUsingPreset($materialId, Request $request): Response {
        $material = $request->user()->materials()->find($materialId);

        if (!$material) {
            throw ValidationException::withMessages([ 'materialId' => 'No such material.' ]);
        }

        $printer = $request->printer;

        $this->checkConnectivityOrFail($printer);

        $statistics = $printer->getStatistics();

        if (isset( $statistics['extruders'] )) {
            foreach (array_keys( $statistics['extruders'] ) as $index) {
                $printer->queueCommand( "M104 I{$index} S{$material->temperatures['hotend']}" );
            }
        }

        $printer->queueCommand( "M104 S{$material->temperatures['hotend']}" );
        $printer->queueCommand( "M140 S{$material->temperatures['bed']}" );

        return response('');
    }

    public function queueCommand(Request $request) {
        $command = $request->input('command');

        if (empty( trim($command) )) {
            throw ValidationException::withMessages([ 'command' => 'Can\'t queue an empty command.' ]);
        }

        $printer = $request->printer;

        $this->checkConnectivityOrFail($printer);

        Log::info( __METHOD__ . ': ' . $command );

        
        $printer->queueCommand( $command );
    }

    private function getStartLine(Request $request): int {
        return
            $request->filled('startLine')
                ? $request->get('startLine')
                : 0;
    }

    private function getEndLine(Request $request): ?int {
        return
            $request->filled('endLine')
                ? $request->get('endLine')
                : null;
    }

    public function getLinesFromActiveFile(Request $request) {
        $gcodeStream = $this->prepareActiveFile($request);

        rewind($gcodeStream);

        $startLine  = $this->getStartLine($request);
        $endLine    = $this->getEndLine($request);

        $targetLayer = $request->get('targetLayer');

        $lastMovementMode = null;

        Log::debug( __METHOD__ . ": PRE: startLine = {$startLine}, endLine = {$endLine}, targetLayer = {$targetLayer}" );

        if ($targetLayer === null) {
            if (!$endLine) {
                $printer = $request->printer;

                if ($printer) {
                    $endLine = $printer->getCurrentLine();
                }
            }
        } else { $endLine = null; }

        Log::debug( __METHOD__ . ": POST: startLine = {$startLine}, endLine = {$endLine}, targetLayer = {$targetLayer}" );

        $streamMaxLengthBytes = $request->streamMaxLengthBytes;

        $layerNumber = 0;
        $lineNumber  = 0;
        $percentage  = 0;

        // send lines to client up to $currentLine
        while (
            $line = readStreamLine(
                stream:    $gcodeStream,
                maxLength: $streamMaxLengthBytes
            )
        ) {
            $line = getGCode( $line );

            if (!$line) continue;

            print "$line" . PHP_EOL;

            if ($endLine !== null) {
                $nextPercentage =
                    $endLine === 0
                        ? 100
                        : round(($lineNumber * 100) / $endLine);

                if ($percentage != $nextPercentage) {
                    $percentage = $nextPercentage;

                    print ";P={$percentage}" . PHP_EOL;
                }

                if ($lineNumber == $endLine) break;
            }

            $lineNumber++;

            if (Str::startsWith($line, 'G90') || Str::startsWith($line, 'G91')) {
                $lastMovementMode = $line;
            }

            if (!Str::startsWith($line, 'G0') && !Str::startsWith($line, 'G1')) { continue; }

            $nextVirtualPosition = movementToXYZE( $line );

            if (!isset( $virtualPosition )) {
                $virtualPosition = [ 'z' => 0 ];
            }

            if ($lastMovementMode === null || $lastMovementMode == 'G90') {
                if (isset($nextVirtualPosition['z']) && $nextVirtualPosition['z'] != $virtualPosition['z']) {
                    $virtualPosition['z'] = $nextVirtualPosition['z'];

                    $layerNumber++;
                }
            } else {
                $nextVirtualPosition['z'] += $virtualPosition['z'];

                if ($nextVirtualPosition['z'] != $virtualPosition['z']) {
                    $layerNumber++;
                }
            }

            if ($targetLayer !== null && $layerNumber == $targetLayer) {
                break;
            }
        }

        $percentage =
            $endLine === null || $endLine === 0
                ? 100
                : ($lineNumber * 100) / $endLine;

        print ";P={$percentage}" . PHP_EOL;
    }

    public function getLineCountFromActiveFile(Request $request) {
        $gcodeStream = $this->prepareActiveFile($request);

        rewind($gcodeStream);

        $lineCount = 0;

        while (readStreamLine($gcodeStream)) { $lineCount++; }

        return $lineCount;
    }

    private function prepareActiveFile(Request $request): mixed {
        $request->validate([
            'startLine' => 'sometimes|integer',
            'endLine'   => 'sometimes|integer'
        ]);

        $printer = $request->printer;

        if (!$printer->connected) {
            throw ValidationException::withMessages([ 'startLine' => 'Couldn\'t complete action: this printer is not connected.' ]);
        }

        if (!$printer->activeFile) {
            throw ValidationException::withMessages([ 'startLine' => 'Couldn\'t complete action: there\'s no active file.' ]);
        }

        $gcode = $this->gcodeStorage->getDriver()->readStream($printer->activeFile);

        if (!$gcode) {
            throw new InitializationException("failed to open {$printer->activeFile}.");
        }

        return $gcode;
    }

    public function startPrint(Request $request) {
        $request->validate([ 'fileName' => 'required' ]);

        $fileName = $request->get('fileName');

        $subDirectory = $request->get('subDirectory');

        if ($subDirectory) {
            $fileName = "{$subDirectory}/{$fileName}";
        }

        if (!$this->gcodeStorage->exists($fileName)) {
            throw ValidationException::withMessages([ 'fileName' => 'No such file.' ]);
        }

        $printer = $request->printer;

        if (!$printer->connected) {
            throw ValidationException::withMessages([ 'fileName' => 'Couldn\'t complete action: this printer is not connected.' ]);
        }

        if ($printer->activeFile) {
            throw ValidationException::withMessages([ 'fileName' => 'Couldn\'t complete action: an active file is already present.' ]);
        }

        // Reset the printer's paused state in case it was left paused.
        $printer->resume();

        $printer->hasActiveJob = true;
        $printer->activeFile   = $fileName;
        $printer->save();

        $printer->setCurrentLine(0);
        $printer->setCurrentLayer(0);

        // TODO: Rewrite the entire print logic to support absolute paths
        PrintGcode::dispatch(
            $request->user(),       // owner
            $printer->_id           // printerId
        );
    }

    public function pausePrint(Request $request) {
        $printer = $request->printer;

        $this->checkConnectivityOrFail($printer);

        if (!$printer->activeFile) {
            throw ValidationException::withMessages([ 'printer' => 'Couldn\'t complete action: there\'s no active file.' ]);
        }

        $printer->pause();
    }

    public function resumePrint(Request $request) {
        $printer = $request->printer;

        $this->checkConnectivityOrFail($printer);

        if (!$printer->activeFile) {
            throw ValidationException::withMessages([ 'printer' => 'Couldn\'t complete action: there\'s no active file.' ]);
        }

        $printer->resume();
    }

    public function cancelPrint(Request $request) {
        $printer = $request->printer;

        $this->checkConnectivityOrFail($printer);

        if (!$printer->activeFile) {
            throw ValidationException::withMessages([ 'printer' => 'Couldn\'t complete action: there\'s no active file.' ]);
        }

        $printer->hasActiveJob = false;
        $printer->activeFile   = null;
        $printer->save();

        SystemMessage::send('refreshActiveFile');
    }

    private function abortRecovery(Printer $printer): void {
        $printer->activeFile       = null;
        $printer->hasActiveJob     = false;
        $printer->lastJobHasFailed = false;
        $printer->lastLine         = null;
        $printer->save();
    }

    public function skipRecovery(Request $request) {
        $this->abortRecovery($request->printer);
    }

    public function recoverPrint(Request $request) {
        $request->validate([ 'startFrom' => 'required|integer|min:0' ]);

        $startFrom = $request->get('startFrom');

        $printer = $request->printer;

        $this->checkConnectivityOrFail($printer);

        if (!$printer->activeFile) {
            throw ValidationException::withMessages([ 'printer' => 'Couldn\'t complete action: there\'s no active file.' ]);
        }

        $jobRestorationHomingTemperature = Configuration::get('jobRestorationHomingTemperature');

        $streamMaxLengthBytes = Configuration::get('streamMaxLengthBytes');

        if (mapperIsRunning()) {
            throw ValidationException::withMessages([ 'printer' => 'Couldn\'t complete action: the mapper is running, please try again in a few seconds.' ]);
        }

        $printer->refresh();

        if (!$printer->connected) {
            throw ValidationException::withMessages([ 'printer' => 'Couldn\'t complete action: this printer is not connected.' ]);
        }

        if (!$printer->node) {
            throw ValidationException::withMessages([ 'printer' => 'we don\'t know about this printer\'s node, please unplug the USB cable and plug it back in, then, wait a few seconds and try again.' ]);
        }

        $preWarmUpCommands  = [];
        $warmUpCommands     = [];
        $preSetUpCommands   = [];
        $setUpCommands      = [];
        $postSetUpCommands  = [];

        // default movement mode for Marlin is absolute
        $lastMovementMode   = 'G90';

        // last tool change (T0, T1, etc.)
        $lastToolChange     = null;

        // (optional) M82 (absolute) or M83 (relative)
        $lastExtruderMode   = null;

        $previousPosition = [
            'x' => null,
            'y' => null,
            'z' => null,
            'e' => null
        ];

        $absolutePosition = $previousPosition;

        $minLayerPositionXY = [ 'x' => null, 'y' => null ];

        $gcode = $this->gcodeStorage->getDriver()->readStream($printer->activeFile);

        /*
         * This block ensures that the RECOVERED_FILE_PREFIX + time() string
         * combination doesn't get stacked multiple times on a filename. This
         * issue could appear if a print fails multiple times.
         * 
         * Result is 'rec_##########_cube'.
         */
        $newFileName =
            self::RECOVERED_FILE_PREFIX . '_' . time() . // rec_##########
            '_' .
            Str::of( basename($printer->activeFile) )->replaceMatches('/' . self::RECOVERED_FILE_PREFIX . '_[0-9]*_/', ''); // 'rec_##########_cube' => 'cube'

        $absolutePath = $this->gcodeStorage->path($newFileName);

        $targetFile = fopen(
            filename: $absolutePath,
            mode:     'w' // Create the file, then, open for r/w.
        );

        $lineNumber      = 0;
        $lineNumberCount = 0;

        RecoveryStageChanged::dispatch(
            $printer->_id,              // printerId
            RecoveryStage::COUNT_LINES  // stage
        );

        while (
            $line = readStreamLine(
                stream:    $gcode,
                maxLength: $streamMaxLengthBytes
            )
        ) {
            $line = getGCode( $line );

            if (!$line) continue;

            /*
             * If a color swap is detected, we're gonna do the conversion in
             * memory, just in order to count the lines that would've been
             * added and thus, making sure that the line number is matched
             * properly.
             */
            if (str_starts_with($line, 'M600')) {
                $lineNumberCount += count(
                    convertColorSwapToSequence(
                        command:          $line,
                        lastMovementMode: $lastMovementMode
                    )
                );
            } else {
                $lineNumberCount++;
            }

            if ($lineNumberCount < $printer->lastLine) {
                $previousPosition = $absolutePosition;

                if ($line == 'G90' || $line == 'G91') {
                    $lastMovementMode = (string) $line;
                } else if (
                    (str_starts_with($line, 'G0') || str_starts_with($line, 'G1'))
                    &&
                    !str_ends_with($line, (';' . FormatterCommands::IGNORE_POSITION_CHANGE))
                ) {
                    if ($lastMovementMode == 'G90') { // absolute mode
                        foreach (movementToXYZE( $line ) as $key => $value) {
                            $absolutePosition[ $key ] = $value;
                        }
                    } else if ($lastMovementMode == 'G91') { // relative mode
                        foreach (movementToXYZE( $line ) as $key => $value) {
                            if ($absolutePosition[ $key ] === null) {
                                $absolutePosition[ $key ]  = $value;
                            } else {
                                $absolutePosition[ $key ] += $value;
                            }
                        }
                    }
                } else if (str_starts_with($line, 'T')) { // tool/extruder change
                    $lastToolChange = (string) $line;
                } else if (
                    str_starts_with($line, 'M104')  // set hotend temperature
                    ||
                    str_starts_with($line, 'M140')  // set bed temperature
                    ||
                    str_starts_with($line, 'M109')  // wait for hotend temperature
                    ||
                    str_starts_with($line, 'M190')  // wait for bed temperature
                ) {
                    $warmUpCommands[] = (string) $line;
                } else if (
                    $line == 'M82'   // (E) extruder absolute mode
                    ||
                    $line == 'M83'   // (E) extruder relative mode
                    ||
                    $line == 'G21'   // use millimeters to measure distances
                ) {
                    $lastExtruderMode = (string) $line;
                }

                if ($absolutePosition['x'] !== null && $absolutePosition['y'] !== null) {
                    if ($minLayerPositionXY['x'] === null || $absolutePosition['x'] < $minLayerPositionXY['x']) {
                        $minLayerPositionXY['x'] = $absolutePosition['x'];
                    }

                    if ($minLayerPositionXY['y'] === null || $absolutePosition['y'] < $minLayerPositionXY['y']) {
                        $minLayerPositionXY['y'] = $absolutePosition['y'];
                    }
                }

                // Reset on layer change
                if ($absolutePosition['z'] != $previousPosition['z']) {
                    $minLayerPositionXY = [ 'x' => null, 'y' => null ];
                }
            } else {
                foreach (array_keys($absolutePosition) as $key) {
                    if ($absolutePosition[$key] === null) {
                        Log::warning("{$printer->node}: {$printer->activeFile}: failed to assert absolute position, forcibly aborting job recovery. | X = {$absolutePosition['x']} - Y = {$absolutePosition['y']} - Z = {$absolutePosition['z']} - E = {$absolutePosition['e']}");

                        $this->abortRecovery($printer);

                        fclose( $targetFile );      // close stream

                        unlink( $absolutePath );    // delete the file

                        throw ValidationException::withMessages([ 'printer' => 'Failed to assert absolute position (not enough context in G-code).' ]);
                    }
                }
            }
        }

        Log::debug( __METHOD__ . ': absolutePosition: ' . json_encode($absolutePosition) );

        $preSetUpCommands[] = 'G90';                                        // absolute mode
        $preSetUpCommands[] = 'G92 X0 Y0 Z0 E0';                            // set all axis to 0
        $preSetUpCommands[] = 'G1 E-' . self::REC_E_AXIS_RETRACT_OFFSET;    // retract N mm
        $preSetUpCommands[] = "M109 R{$jobRestorationHomingTemperature}";   // wait for hotend cooldown

        /*
         * This is gonna be the default target position, unless a minimum
         * position is known.
         */
        $startPos = [
            'x' => $absolutePosition['x'] + self::REC_X_AXIS_HOLDING_OFFSET,
            'y' => $absolutePosition['y'] + self::REC_Y_AXIS_HOLDING_OFFSET
        ];

        Log::debug('$startPos: defaults prepared: ' . json_encode( $startPos ));

        /*
         * If a minimum position is known, we're gonna try to go from $startPos
         * and offset that by XY_AXIS_WITH_MIN_HOLDING_OFFSET on both X and Y
         * axis.
         */
        if (
            $minLayerPositionXY['x'] !== null && $minLayerPositionXY['y'] !== null
            &&
            $startPos['x'] + self::REC_XY_AXIS_WITH_MIN_HOLDING_OFFSET > $minLayerPositionXY['x']
            &&
            $startPos['y'] - self::REC_XY_AXIS_WITH_MIN_HOLDING_OFFSET > $minLayerPositionXY['y']
        ) {
            $startPos['x'] += self::REC_XY_AXIS_WITH_MIN_HOLDING_OFFSET;
            $startPos['y'] -= self::REC_XY_AXIS_WITH_MIN_HOLDING_OFFSET;

            Log::debug('$startPos: minimum layer XY available, decreasing XY to a safer resting position: ' . json_encode( $startPos ) . ', minimum is: ' . json_encode( $minLayerPositionXY ));
        }

        if ($lastToolChange) {
            $preWarmUpCommands[] = $lastToolChange;
        }

        $setUpCommands[] = 'G28 X Y R0';                               // auto-home X and Y (avoid Z-raise by specifying R0)
        $setUpCommands[] = "G0  X{$startPos['x']} Y{$startPos['y']}";  // move to target X/Y + offset (Z is still at virtual 0 here)

        if ($printer->supports('zProbe')) {
            $setUpCommands[] = 'G90';                                       // absolute mode
            $setUpCommands[] = 'G92 Z'  . self::REC_Z_PROBE_HOMING_OFFSET;  // make the printer think it's still at Z_PROBE_HOMING_OFFSET
            $setUpCommands[] = 'G0  Z-' . self::REC_Z_PROBE_HOMING_OFFSET;  // discard additional Z_PROBE_HOMING_OFFSET Z raise (for z-probe)
            $setUpCommands[] = 'G92 Z0';                                    // make the printer think it's still at Z0
        }

        $postSetUpCommands[] = "G0  X{$absolutePosition['x']} Y{$absolutePosition['y']}";   // move to target X/Y
        $postSetUpCommands[] = "G92 Z{$absolutePosition['z']} E{$absolutePosition['e']}";   // set virtual Z height and E position to the original absolute
        $postSetUpCommands[] = 'G1  E' . self::REC_E_AXIS_RETRACT_OFFSET;                   // de-retract N mm
        $postSetUpCommands[] = $lastMovementMode;

        if ($lastExtruderMode) {
            $postSetUpCommands[] = $lastExtruderMode;
        }

        Log::debug('preWarmUpCommands: ' . json_encode( $preWarmUpCommands ));
        Log::debug('warmUpCommands: '    . json_encode( $warmUpCommands    ));
        Log::debug('preSetUpCommands: '  . json_encode( $preSetUpCommands  ));
        Log::debug('setUpCommands: '     . json_encode( $setUpCommands     ));
        Log::debug('postSetUpCommands: ' . json_encode( $postSetUpCommands ));

        fwrite(
            stream: $targetFile,
            data: implode(
                separator: PHP_EOL,
                array: array_merge(
                    $preWarmUpCommands,
                    $warmUpCommands,
                    $preSetUpCommands,
                    $setUpCommands,
                    $warmUpCommands,
                    $postSetUpCommands,
                    [ '; End of recovery sequence' ]
                )
            ) . PHP_EOL
        );

        rewind( $gcode );

        RecoveryStageChanged::dispatch(
            $printer->_id,              // printerId
            RecoveryStage::PARSE_FILE   // stage
        );

        $progressPercentage = 0;

        while (
            $line = readStreamLine(
                stream:    $gcode,
                maxLength: $streamMaxLengthBytes
            )
        ) {
            $line = getGCode( $line );

            if (!$line) continue;

            /*
             * If a color swap is detected, we're gonna do the conversion in
             * memory, just in order to count the lines that would've been
             * added and thus, making sure that the line number is matched
             * properly.
             */
            if (str_starts_with($line, 'M600')) {
                $lineNumber += count(
                    convertColorSwapToSequence(
                        command:          $line,
                        lastMovementMode: $lastMovementMode
                    )
                );
            } else {
                $lineNumber++;
            }

            if ($lineNumber >= $startFrom) {
                fwrite(
                    stream: $targetFile,
                    data:   $line . PHP_EOL
                );
            }

            $newProgressPercentage = ($lineNumber * 100) / $lineNumberCount;

            if ($newProgressPercentage > 1) {
                $newProgressPercentage = ceil( $newProgressPercentage );
            } else if ($newProgressPercentage > 100) {
                $newProgressPercentage = 100;
            } else {
                $newProgressPercentage = round($newProgressPercentage);
            }

            if ($progressPercentage != $newProgressPercentage) {
                $progressPercentage = $newProgressPercentage;

                RecoveryProgress::dispatch(
                    $printer->_id,       // printerId
                    $progressPercentage  // stage
                );
            }
        }

        fclose($targetFile);

        $printer->activeFile       = $newFileName;
        $printer->hasActiveJob     = true;
        $printer->lastJobHasFailed = false;
        $printer->save();

        PrintGcode::dispatch(
            Auth::user(),   // owner
            $printer->_id   // printerId
        );
    }

    public function handleControlCommand(Request $request) {
        $direction = $request->input('direction');
        $distance  = $request->input('distance');
        $feedrate  = $request->input('feedrate');

        if (empty( trim($direction) )) {
            throw ValidationException::withMessages([ 'direction' => 'Can\'t queue an empty direction.' ]);
        }

        if (!strlen($distance)) {
            throw ValidationException::withMessages([ 'distance' => 'Can\'t queue a command without a distance.' ]);
        }

        if (!strlen($feedrate)) {
            throw ValidationException::withMessages([ 'feedrate' => 'Can\'t queue a command without a feedrate.' ]);
        }

        if (!ControlDirection::hasKey($direction)) {
            throw ValidationException::withMessages([ 'direction' => 'Invalid direction.' ]);
        }

        $command = Str::replace(
            search:  [ '{distance}', '{feedrate}' ],
            replace: [ $distance,    $feedrate    ],
            subject: ControlDirection::getValue($direction)
        );

        $printer = $request->printer;

        if (!$printer->connected) {
            throw ValidationException::withMessages([ 'command' => 'Couldn\'t queue command: this printer is not connected.' ]);
        }

        Log::info( __METHOD__ . ': ' . json_encode(request()->all()) );

        $printer->queueCommand('G91');
        $printer->queueCommand($command);
    }

    public function getControlDirections(): array {
        return ControlDirection::asArray();
    }

    public function handleExtrusion(Request $request) {
        $extruder = $request->input('extruder');
        $distance = $request->input('distance');

        if (!strlen($extruder)) {
            throw ValidationException::withMessages([ 'extruder' => 'Can\'t queue a command without an extruder.' ]);
        }

        if (!strlen($distance)) {
            throw ValidationException::withMessages([ 'distance' => 'Can\'t queue a command without a distance.' ]);
        }

        $printer = $request->printer;

        if (!$printer->connected) {
            throw ValidationException::withMessages([ 'direction' => 'Couldn\'t queue command: this printer is not connected.' ]);
        }

        $feedrate = Configuration::get('controlExtrusionFeedrate');

        $printer->queueCommand("T{$extruder}");
        $printer->queueCommand(
            Str::replace(
                search:  [ '{distance}', '{feedrate}' ],
                replace: [ $distance,    $feedrate    ],
                subject: 'G1 E{distance} F{feedrate}'
            )
        );
    }

    public function handleRetraction(Request $request) {
        $extruder = $request->input('extruder');
        $distance = $request->input('distance');

        if (!strlen($extruder)) {
            throw ValidationException::withMessages([ 'extruder' => 'Can\'t queue a command without an extruder.' ]);
        }

        if (!strlen($distance)) {
            throw ValidationException::withMessages([ 'distance' => 'Can\'t queue a command without a distance.' ]);
        }

        $printer = $request->printer;

        if (!$printer->connected) {
            throw ValidationException::withMessages([ 'direction' => 'Couldn\'t queue command: this printer is not connected.' ]);
        }

        $feedrate = Configuration::get('controlExtrusionFeedrate');

        $printer->queueCommand("T{$extruder}");
        $printer->queueCommand(
            Str::replace(
                search:  [ '{distance}', '{feedrate}' ],
                replace: [ $distance,    $feedrate    ],
                subject: 'G1 E-{distance} F{feedrate}'
            )
        );
    }

    public function handleHotendTemperature(Request $request) {
        $temperature = $request->input('temperature');

        if (!strlen($temperature)) {
            throw ValidationException::withMessages([ 'temperature' => 'Can\'t queue a command without a temperature.' ]);
        }

        if (!is_numeric($temperature)) {
            throw ValidationException::withMessages([ 'temperature' => 'Temperature must be a number.' ]);
        }

        $printer = $request->printer;

        if (!$printer->connected) {
            throw ValidationException::withMessages([ 'temperature' => 'Couldn\'t queue command: this printer is not connected.' ]);
        }

        $printer->queueCommand("M104 S{$temperature}");
    }

    public function handleBedTemperature(Request $request) {
        $temperature = $request->input('temperature');

        if (!strlen($temperature)) {
            throw ValidationException::withMessages([ 'temperature' => 'Can\'t queue a command without a temperature.' ]);
        }

        if (!is_numeric($temperature)) {
            throw ValidationException::withMessages([ 'temperature' => 'Temperature must be a number.' ]);
        }

        $printer = $request->printer;

        if (!$printer->connected) {
            throw ValidationException::withMessages([ 'temperature' => 'Couldn\'t queue command: this printer is not connected.' ]);
        }

        $printer->queueCommand("M140 S{$temperature}");
    }

    public function getRecordings(Request $request): array {
        $disk = Storage::disk('recordings');

        return $request->printer->videos()->get()->map(function ($video) use ($disk) {
            $basename = pathinfo($video->fileName, PATHINFO_FILENAME);

            $lastModified = $disk->lastModified($video->fileName);

            return [
                'id'            => $video->_id,
                'url'           => request()->schemeAndHttpHost() . '/' . RenderVideo::RECORDINGS_DIRECTORY . '/' . $video->fileName,
                'thumb'         => request()->schemeAndHttpHost() . '/' . RenderVideo::RECORDINGS_DIRECTORY . '/' . $video->thumbnail,
                'name'          => $basename,
                'sizeBytes'     => $disk->size($video->fileName),
                'modified'      => Carbon::createFromTimestamp( $lastModified )->diffForHumans(),
                'durationSecs'  => $video->duration,
                'deletable'     => $video->isComplete
            ];
        })->toArray();
    }

    public function deleteRecording($recordingId, Request $request) {
        $video = $request->printer->videos()->find($recordingId);

        if (!$video) {
            throw ValidationException::withMessages([ 'recordingId' => 'No such recording.' ]);
        }

        $disk = Storage::disk('recordings');

        $disk->delete($video->fileName);
        $disk->delete($video->thumbnail);

        $video->delete();
    }

    public function linkCamera($cameraId, Request $request) {
        $camera = Camera::find($cameraId);

        if (!$camera) {
            throw ValidationException::withMessages([ 'cameraId' => 'No such camera.' ]);
        }

        $printer = $request->printer;

        if (!$printer->cameras) {
            $printer->cameras = [];
        }

        $cameras = $printer->cameras;

        if (!in_array($cameraId, $cameras)) {
            $cameras[] = $cameraId;
        }

        $printer->cameras = $cameras;
        $printer->save();
    }

    public function unlinkCamera($cameraId, Request $request) {
        $camera = Camera::find($cameraId);

        if (!$camera) {
            throw ValidationException::withMessages([ 'cameraId' => 'No such camera.' ]);
        }

        $printer = $request->printer;

        if (!$printer->cameras) {
            $printer->cameras = [];
        }

        if (!$printer->recordableCameras) {
            $printer->recordableCameras = [];
        }

        $printer->cameras           = array_filter($printer->cameras,           fn($id) => $id != $cameraId);
        $printer->recordableCameras = array_filter($printer->recordableCameras, fn($id) => $id != $cameraId);
        $printer->save();
    }

    public function enableRecording($cameraId, Request $request) {
        $camera = Camera::find($cameraId);

        if (!$camera) {
            throw ValidationException::withMessages([ 'cameraId' => 'No such camera.' ]);
        }

        $printer = $request->printer;

        if (!in_array($cameraId, $printer->cameras)) {
            $this->linkCamera($cameraId, $request);
        }

        $recordableCameras = $printer->recordableCameras;

        if (!in_array($cameraId, $recordableCameras)) {
            $recordableCameras[] = $cameraId;
        }

        $printer->recordableCameras = $recordableCameras;
        $printer->save();
    }

    public function disableRecording($cameraId, Request $request) {
        $camera = Camera::find($cameraId);

        if (!$camera) {
            throw ValidationException::withMessages([ 'cameraId' => 'No such camera.' ]);
        }

        $printer = $request->printer;

        $recordableCameras = $printer->recordableCameras;
        $recordableCameras = array_filter($recordableCameras, fn($id) => $id != $cameraId);

        $printer->recordableCameras = $recordableCameras;
        $printer->save();
    }

    public function getPrintStatus(Request $request) {
        $printer = $request->printer;

        return [
            'activeFile'        => $printer->activeFile       ?? null,
            'hasActiveJob'      => $printer->hasActiveJob     ?? false,
            'lastJobHasFailed'  => $printer->lastJobHasFailed ?? false,
            'lastLine'          => $printer->lastLine         ?? 0
        ];
    }

}
