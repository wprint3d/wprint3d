<?php

namespace App\Http\Controllers\OctoPrint;

use App\Exceptions\PrintJobException;
use App\Http\Controllers\Controller;
use App\Models\Printer;
use App\Services\OctoPrint\OctoPrintContext;
use App\Services\PrintJobService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PrinterController extends Controller
{
    public function __construct(
        private readonly OctoPrintContext $context,
        private readonly PrintJobService $jobs,
    ) {}

    public function printer(Request $request): JsonResponse
    {
        $printer = $this->context->printer($request);

        return response()->json([
            'temperature' => $this->temperature($printer),
            'state' => $this->state($printer),
        ]);
    }

    public function connection(Request $request): JsonResponse
    {
        $printer = $this->context->printer($request);

        return response()->json([
            'current' => [
                'state' => $this->stateText($printer),
                'port' => $printer->node,
                'baudrate' => $printer->baudRate,
                'printerProfile' => '_default',
            ],
            'options' => [
                'ports' => [$printer->node],
                'baudrates' => [(int) $printer->baudRate],
                'printerProfiles' => [['_default' => ['name' => data_get($printer, 'machine.machineType', 'WPrint 3D')]]],
                'portPreference' => $printer->node,
                'baudratePreference' => (int) $printer->baudRate,
                'printerProfilePreference' => '_default',
                'autoconnect' => true,
            ],
        ]);
    }

    public function job(Request $request): JsonResponse
    {
        $printer = $this->context->printer($request);
        $currentLine = $printer->getCurrentLine();
        $maxLine = $printer->getMaxLine();

        return response()->json([
            'job' => [
                'file' => [
                    'name' => $printer->activeFile ? basename($printer->activeFile) : null,
                    'origin' => $printer->activeFile ? 'local' : null,
                    'path' => $printer->activeFile,
                    'size' => null,
                    'date' => null,
                ],
                'estimatedPrintTime' => null,
                'lastPrintTime' => null,
                'filament' => null,
            ],
            'progress' => [
                'completion' => $maxLine > 0 ? round(($currentLine * 100) / $maxLine, 2) : null,
                'filepos' => $currentLine,
                'printTime' => null,
                'printTimeLeft' => null,
                'printTimeLeftOrigin' => null,
            ],
            'state' => $this->stateText($printer),
        ]);
    }

    public function command(Request $request): Response
    {
        $printer = $this->context->printer($request);
        $command = $request->input('command');

        try {
            if ($command === 'start') {
                $path = $this->context->selectedFile($request);

                if (! $path) {
                    return response()->json(['error' => 'Select a local G-code file first.'], 409);
                }

                $this->jobs->start($request->user(), $printer, $path);
            } elseif ($command === 'cancel') {
                $this->jobs->cancel($printer);
            } elseif ($command === 'pause') {
                $action = $request->input('action', 'toggle');

                if ($action === 'resume' || ($action === 'toggle' && ! $printer->isRunning())) {
                    $this->jobs->resume($printer);
                } elseif ($action === 'pause' || $action === 'toggle') {
                    $this->jobs->pause($printer);
                } else {
                    return response()->json(['error' => 'Unsupported pause action.'], 400);
                }
            } else {
                return response()->json(['error' => 'Unsupported job command.'], 400);
            }

            return response('', Response::HTTP_NO_CONTENT);
        } catch (PrintJobException $exception) {
            return response()->json([
                'error' => $exception->getMessage(),
                'reason' => $exception->reason,
            ], Response::HTTP_CONFLICT);
        }
    }

    private function state(Printer $printer): array
    {
        $printing = (bool) $printer->activeFile;
        $paused = $printing && ! $printer->isRunning();
        $connected = (bool) $printer->connected;

        return [
            'text' => $this->stateText($printer),
            'flags' => [
                'operational' => $connected,
                'paused' => $paused,
                'printing' => $printing && ! $paused,
                'cancelling' => false,
                'pausing' => false,
                'resuming' => false,
                'finishing' => false,
                'closedOrError' => ! $connected,
                'error' => ! $connected,
                'ready' => $connected && ! $printing,
                'sdReady' => false,
            ],
        ];
    }

    private function stateText(Printer $printer): string
    {
        if (! $printer->connected) {
            return 'Offline';
        }

        if ($printer->activeFile && ! $printer->isRunning()) {
            return 'Paused';
        }

        return $printer->activeFile ? 'Printing' : 'Operational';
    }

    private function temperature(Printer $printer): array
    {
        $statistics = $printer->getStatistics();
        $result = [];

        foreach ($statistics['extruders'] ?? [] as $index => $extruder) {
            $result['tool'.$index] = [
                'actual' => (float) ($extruder['temperature'] ?? 0),
                'target' => (float) ($extruder['target'] ?? 0),
                'offset' => 0,
            ];
        }

        if (isset($statistics['bed'])) {
            $result['bed'] = [
                'actual' => (float) ($statistics['bed']['temperature'] ?? 0),
                'target' => (float) ($statistics['bed']['target'] ?? 0),
                'offset' => 0,
            ];
        }

        return $result;
    }
}
