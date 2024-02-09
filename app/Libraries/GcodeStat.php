<?php

namespace App\Libraries;

use Illuminate\Support\Facades\Storage;

use Illuminate\Support\Str;

use Symfony\Component\Process\Process;

use Exception;

class GcodeStat {

    private string $filePath;

    public function __construct(string $filePath) {
        $this->filePath = $filePath;

        // Resolve relative to absolute paths
        if (!Str::startsWith($this->filePath, '/')) {
            $this->filePath = Storage::path($this->filePath);
        }
    }

    public function getPrintTimeSeconds(): int|null {
        $process = new Process([ 'gcodestat', '-Q', '-g', $this->filePath ]);
        $process->run();

        if ($process->getExitCode() != 0) {
            $errorMessage = $process->getErrorOutput();
            $errorMessage = explode(PHP_EOL, $errorMessage)[0];

            throw new Exception($errorMessage);
        }

        return $process->getOutput();
    }

}

?>