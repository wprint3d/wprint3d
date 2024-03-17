<?php

namespace App\Livewire;

use Illuminate\Support\Str;

use Livewire\Component;

use Carbon\Carbon;

use Illuminate\Support\Facades\Log;

use PhpZip\ZipFile;

use Spatie\TemporaryDirectory\TemporaryDirectory;

class DevelopmentSettingsDumpLogs extends Component
{

    // Files declared here will be added regardless of date-based rules
    const ALWAYS_ADD_NAMES = [
        'control_worker.log',
        'octane-server-state.json',
        'prints_worker.log',
        'swoole_http.log'
    ];

    const ZIP_FILENAME = 'logs-%s.zip';

    public function dump() {
        $temporaryDirectory = TemporaryDirectory::make();

        $carbon = new Carbon();

        $zipFilePath = $temporaryDirectory->path(
            Str::replace(
                search:  '%s',
                replace: $carbon->format('Y-m-d_H:i:s'),
                subject: self::ZIP_FILENAME
            )
        );

        $logsPath = storage_path('logs');

        $logFiles = scandir($logsPath);

        $validSuffixes = [ "-{$carbon->format('Y-m-d')}.log" ];

        $carbon->subDay();

        $validSuffixes[] = "-{$carbon->format('Y-m-d')}.log";

        Log::info(json_encode($validSuffixes));

        $zipFile = new ZipFile();

        foreach ($logFiles as $logFile) {
            if (
                !Str::endsWith($logFile, $validSuffixes)
                &&
                !in_array($logFile, self::ALWAYS_ADD_NAMES)
            ) { continue; }

            $zipFile->addFile(
                filename:   $logsPath . '/' . $logFile,
                entryName:  $logFile
            );
        }

        $zipFile->saveAsFile($zipFilePath);

        return response()->download(
            file:   $zipFilePath,
            name:   basename($zipFilePath)
        );
    }

    public function render()
    {
        return view('livewire.development-settings-dump-logs');
    }
}
