<?php

namespace App\Http\Controllers\OctoPrint;

use App\Exceptions\PrintJobException;
use App\Http\Controllers\Controller;
use App\Models\Printer;
use App\Services\OctoPrint\OctoPrintContext;
use App\Services\OctoPrint\PrinterControlService;
use App\Services\PrintJobService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PrinterController extends Controller
{
    public function __construct(
        private readonly OctoPrintContext $context,
        private readonly PrintJobService $jobs,
        private readonly PrinterControlService $controls,
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

    public function tool(Request $request): JsonResponse
    {
        $temperature = $this->temperature($this->context->printer($request));

        return response()->json(array_filter(
            $temperature,
            fn (string $key) => str_starts_with($key, 'tool'),
            ARRAY_FILTER_USE_KEY,
        ));
    }

    public function bed(Request $request): JsonResponse
    {
        $temperature = $this->temperature($this->context->printer($request));

        return response()->json([
            'bed' => $temperature['bed'] ?? [
                'actual' => 0.0,
                'target' => 0.0,
                'offset' => 0,
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

    public function printheadCommand(Request $request): Response
    {
        $printer = $this->context->printer($request);
        $command = $request->input('command');

        return $this->controlResponse(function () use ($request, $printer, $command) {
            if ($command === 'jog') {
                if ($request->boolean('absolute')) {
                    throw new \InvalidArgumentException('Absolute jogging is not supported.');
                }

                $axes = array_filter(
                    $request->only(['x', 'y', 'z']),
                    fn ($value) => $value !== null && $value !== '',
                );
                $this->controls->jog($printer, $axes, $request->input('speed', 1500));

                return;
            }

            if ($command === 'home') {
                $this->controls->home($printer, $request->input('axes', []));

                return;
            }

            if ($command === 'feedrate') {
                $this->controls->setFeedrate($printer, $request->input('factor'));

                return;
            }

            throw new \InvalidArgumentException('Unsupported printhead command.');
        });
    }

    public function toolCommand(Request $request): Response
    {
        $printer = $this->context->printer($request);
        $command = $request->input('command');

        return $this->controlResponse(function () use ($request, $printer, $command) {
            if ($command === 'target') {
                $targets = $request->input('targets');

                if (! is_array($targets) || $targets === []) {
                    throw new \InvalidArgumentException('At least one tool target is required.');
                }

                foreach ($targets as $tool => $temperature) {
                    $this->controls->setHotendTarget($printer, $tool, $temperature);
                }

                return;
            }

            if ($command === 'select') {
                $this->controls->selectTool($printer, $request->input('tool'));

                return;
            }

            if ($command === 'extrude') {
                $this->controls->extrude(
                    $printer,
                    $request->input('tool', 'tool0'),
                    $request->input('amount'),
                    $request->input('speed', 300),
                );

                return;
            }

            if ($command === 'flowrate') {
                $this->controls->setFlowrate($printer, $request->input('factor'));

                return;
            }

            throw new \InvalidArgumentException('Unsupported tool command.');
        });
    }

    public function bedCommand(Request $request): Response
    {
        $printer = $this->context->printer($request);

        return $this->controlResponse(function () use ($request, $printer) {
            if ($request->input('command') !== 'target') {
                throw new \InvalidArgumentException('Unsupported build plate command.');
            }

            $this->controls->setBedTarget($printer, $request->input('target'));
        });
    }

    public function rawCommand(Request $request): Response
    {
        $printer = $this->context->printer($request);

        return $this->controlResponse(function () use ($request, $printer) {
            $hasCommand = $request->exists('command');
            $hasCommands = $request->exists('commands');

            if ($request->exists('script')) {
                throw new \InvalidArgumentException('Printer scripts are not supported.');
            }

            if ($hasCommand === $hasCommands) {
                throw new \InvalidArgumentException('Provide either command or commands, but not both.');
            }

            $commands = $hasCommands ? $request->input('commands') : [$request->input('command')];

            if (! is_array($commands) || $commands === [] || count($commands) > 25) {
                throw new \InvalidArgumentException('Provide between 1 and 25 printer commands.');
            }

            $normalized = [];
            foreach ($commands as $command) {
                if (! is_string($command)) {
                    throw new \InvalidArgumentException('Every printer command must be a string.');
                }

                if (strpbrk($command, "\r\n\0") !== false) {
                    throw new \InvalidArgumentException('Printer commands must be single-line strings of at most 512 characters.');
                }

                $command = trim($command);
                if ($command === '' || mb_strlen($command) > 512) {
                    throw new \InvalidArgumentException('Printer commands must be single-line strings of at most 512 characters.');
                }

                $normalized[] = $command;
            }

            $this->controls->sendCommands($printer, $normalized);
        });
    }

    private function controlResponse(callable $operation): Response
    {
        try {
            $operation();

            return response('', Response::HTTP_NO_CONTENT);
        } catch (PrintJobException $exception) {
            return response()->json([
                'error' => $exception->getMessage(),
                'reason' => $exception->reason,
            ], Response::HTTP_CONFLICT);
        } catch (\InvalidArgumentException $exception) {
            return response()->json([
                'error' => $exception->getMessage(),
                'reason' => 'invalid_request',
            ], Response::HTTP_BAD_REQUEST);
        }
    }

    private function state(Printer $printer): array
    {
        $printing = $printer->hasActivePrintJob();
        $recoveryRequired = $printer->hasPendingPrintRecovery();
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
                'error' => ! $connected || $recoveryRequired,
                'ready' => $connected && ! $printing && ! $recoveryRequired,
                'sdReady' => false,
                'wprint3dRecoveryRequired' => $recoveryRequired,
            ],
        ];
    }

    private function stateText(Printer $printer): string
    {
        if (! $printer->connected) {
            return 'Offline';
        }

        if ($printer->hasPendingPrintRecovery()) {
            return 'Recovery required';
        }

        if ($printer->hasActivePrintJob() && ! $printer->isRunning()) {
            return 'Paused';
        }

        return $printer->hasActivePrintJob() ? 'Printing' : 'Operational';
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
