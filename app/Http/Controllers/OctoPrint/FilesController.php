<?php

namespace App\Http\Controllers\OctoPrint;

use App\Exceptions\PrintJobException;
use App\Http\Controllers\Controller;
use App\Services\OctoPrint\GcodeFileService;
use App\Services\OctoPrint\OctoPrintContext;
use App\Services\PrintJobService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class FilesController extends Controller
{
    public function __construct(
        private readonly GcodeFileService $files,
        private readonly OctoPrintContext $context,
        private readonly PrintJobService $jobs,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json(['files' => $this->files->all(), 'free' => null, 'total' => null]);
    }

    public function show(string $path): JsonResponse
    {
        return response()->json($this->files->file($path));
    }

    public function upload(Request $request): JsonResponse
    {
        $request->validate(['file' => 'required|file']);

        try {
            $path = $this->files->store($request->file('file'));
            $print = $request->boolean('print');
            $select = $print || $request->boolean('select');

            if ($select) {
                $printer = $this->context->printer($request);

                $this->jobs->withPrinterLock($printer, function () use ($request, $path, $print, $printer) {
                    $this->context->setSelectedFile($request, $path);

                    if ($print) {
                        $this->jobs->start($request->user(), $printer, $path, true);
                    }
                });
            }

            return response()->json([
                'done' => true,
                'files' => ['local' => $this->files->file($path)],
            ], Response::HTTP_CREATED, [
                'Location' => url('/api/files/local/'.rawurlencode($path)),
            ]);
        } catch (PrintJobException $exception) {
            return $this->conflict($exception);
        }
    }

    public function command(Request $request, string $path): Response
    {
        $path = $this->files->normalizePath($path);

        if (! $this->files->exists($path)) {
            return response()->json(['error' => 'File not found.'], 404);
        }

        $command = $request->input('command');

        if ($command === 'unselect') {
            $this->context->setSelectedFile($request, null);

            return response('', Response::HTTP_NO_CONTENT);
        }

        if ($command !== 'select') {
            return response()->json(['error' => 'Unsupported file command.'], 400);
        }

        try {
            $printer = $this->context->printer($request);

            $this->jobs->withPrinterLock($printer, function () use ($request, $path, $printer) {
                $this->context->setSelectedFile($request, $path);

                if ($request->boolean('print')) {
                    $this->jobs->start($request->user(), $printer, $path, true);
                }
            });

            return response('', Response::HTTP_NO_CONTENT);
        } catch (PrintJobException $exception) {
            return $this->conflict($exception);
        }
    }

    public function destroy(string $path): Response
    {
        try {
            $this->files->delete($path);

            return response('', Response::HTTP_NO_CONTENT);
        } catch (PrintJobException $exception) {
            return $this->conflict($exception);
        }
    }

    private function conflict(PrintJobException $exception): JsonResponse
    {
        return response()->json([
            'error' => $exception->getMessage(),
            'reason' => $exception->reason,
        ], Response::HTTP_CONFLICT);
    }
}
