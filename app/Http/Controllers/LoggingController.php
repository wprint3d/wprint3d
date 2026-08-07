<?php

namespace App\Http\Controllers;

use App\Plugins\PluginSupportBundleService;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipStream\ZipStream;

class LoggingController extends Controller
{
    public function __construct(private PluginSupportBundleService $pluginSupportBundleService) {}

    private function getAll(): array
    {
        return array_values(
            Arr::where(
                array: Storage::disk('logs')->allFiles(),
                callback: function ($value) {
                    return Str::endsWith($value, '.log');
                }
            )
        );
    }

    public function index()
    {
        return $this->getAll();
    }

    public function get($file)
    {
        return Storage::disk('logs')->get($file);
    }

    public function delete(Request $request)
    {
        $request->validate([
            'files' => 'sometimes|array',
            'files.*' => 'string',
        ]);

        $files = $request->input('files');

        if (empty($files)) {
            return Storage::disk('logs')->delete(
                $this->getAll()
            );
        }

        return Storage::disk('logs')->delete($files);
    }

    public function zip(Request $request)
    {
        $request->validate([
            'files' => 'sometimes|array',
            'files.*' => 'string',
        ]);

        $storage = Storage::disk('logs');

        $files = $request->input('files');

        if (empty($files)) {
            $files = $this->getAll();
        }

        $zip = new ZipStream(
            outputName: 'logs_'.now()->format('Y-m-d_H-i-s').'.zip',
            sendHttpHeaders: true
        );

        foreach ($files as $file) {
            $zip->addFile(
                fileName: $file,
                data: $storage->get($file)
            );

            Log::info('File added to zip', ['file' => $file]);
        }

        if ($request->boolean('includePlugins')) {
            $zip->addFile(
                fileName: 'wprint3d/plugin-support.json',
                data: json_encode($this->pluginSupportBundleService->snapshot(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            );
        }

        $zip->finish();
    }
}
