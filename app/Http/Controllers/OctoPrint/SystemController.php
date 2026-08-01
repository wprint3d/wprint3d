<?php

namespace App\Http\Controllers\OctoPrint;

use App\Http\Controllers\Controller;
use App\Models\Printer;
use App\Services\OctoPrint\OctoPrintContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SystemController extends Controller
{
    public function __construct(private readonly OctoPrintContext $context) {}

    public function version(): JsonResponse
    {
        return response()->json([
            'api' => '0.1',
            'server' => '1.11.0',
            'text' => 'OctoPrint 1.11.x compatible (WPrint 3D)',
            'wprint3d' => [
                'compatible' => '1.11.x',
                'version' => config('plugins.core_version', 'unknown'),
            ],
        ]);
    }

    public function printers(Request $request): JsonResponse
    {
        $selected = $this->context->selectedPrinterUuid($request);
        $token = $request->user()?->currentAccessToken();

        if ($token?->printer_uuid) {
            $printer = $this->context->printer($request);

            return response()->json([
                'printers' => [[
                    'uuid' => $token->printer_uuid,
                    'name' => data_get($printer, 'machine.machineType') ?: $token->printer_uuid,
                    'connected' => (bool) $printer->connected,
                    'printing' => $printer->hasActivePrintJob(),
                    'recoveryRequired' => $printer->hasPendingPrintRecovery(),
                    'selected' => true,
                ]],
                'selectedPrinterUuid' => $token->printer_uuid,
            ]);
        }

        $printers = Printer::whereNotNull('machine.uuid')
            ->orderByDesc('connected')
            ->get()
            ->map(fn (Printer $printer) => [
                'uuid' => data_get($printer, 'machine.uuid'),
                'name' => data_get($printer, 'machine.machineType') ?: data_get($printer, 'machine.uuid'),
                'connected' => (bool) $printer->connected,
                'printing' => $printer->hasActivePrintJob(),
                'recoveryRequired' => $printer->hasPendingPrintRecovery(),
                'selected' => data_get($printer, 'machine.uuid') === $selected,
            ])
            ->values();

        return response()->json(['printers' => $printers, 'selectedPrinterUuid' => $selected]);
    }

    public function cameras(Request $request): JsonResponse
    {
        $printer = $this->context->printer($request);
        $cameras = [];

        foreach ($printer->getCameras() as $camera) {
            if (! $camera->enabled) {
                continue;
            }

            $url = is_string($camera->url) ? $camera->url : '';
            $url = preg_match('~^/video/[^/?#]+/(?:uvc|csi)/[0-9]+$~', $url) ? $url : '';
            $cameras[] = [
                'id' => (string) $camera->_id,
                'name' => $camera->label ?: 'Camera',
                'connected' => (bool) $camera->connected,
                'streamUrl' => $url !== '' ? $url.'?action=stream' : null,
                'snapshotUrl' => $url !== '' ? $url.'?action=snapshot' : null,
                'streamsMjpeg' => (bool) ($camera->streamsMjpeg ?? $camera->supportsMjpeg ?? true),
            ];
        }

        return response()->json(['cameras' => $cameras]);
    }

    public function terminal(Request $request): JsonResponse
    {
        $printer = $this->context->printer($request);
        $limit = max(1, min(500, (int) $request->query('limit', 250)));
        $console = str_replace(["\r\n", "\r"], "\n", (string) $printer->getConsole());
        $lines = $console === '' ? [] : explode("\n", rtrim($console, "\n"));
        $total = count($lines);
        $lines = array_slice($lines, -$limit);
        $lines = array_map(
            fn (string $line) => mb_strimwidth($line, 0, 1000, '…'),
            $lines,
        );

        return response()->json([
            'lines' => array_values($lines),
            'total' => $total,
            'truncated' => $total > count($lines),
            'cursor' => hash('sha256', $console),
        ]);
    }

    public function selectPrinter(Request $request): JsonResponse
    {
        $validated = $request->validate(['printerUuid' => 'required|string']);
        $printer = $this->context->selectPrinter($request, $validated['printerUuid']);
        $request->user()->setActivePrinterId((string) $printer->_id);

        return response()->json([
            'printerUuid' => data_get($printer, 'machine.uuid'),
            'connected' => (bool) $printer->connected,
        ]);
    }
}
