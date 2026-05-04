<?php

namespace App\Support;

use Symfony\Component\Process\Process;

class PrinterConnectionDiagnostic
{
    private const MAX_LINES = 12;
    private const MAX_CHARS = 2000;

    private const ERROR_PATTERNS = [
        '/descriptor read/i',
        '/disconnect/i',
        '/error -\d+/i',
        '/attempt power cycle/i',
    ];

    private const CONTEXT_PATTERNS = [
        '/usb/i',
        '/ttyACM/i',
        '/ttyUSB/i',
        '/cdc_acm/i',
        '/descriptor read/i',
        '/disconnect/i',
        '/error -\d+/i',
        '/attempt power cycle/i',
    ];

    public function read(?string $node): ?string
    {
        $process = new Process(['dmesg', '--color=never']);
        $process->setTimeout(2);
        $process->run();

        if (! $process->isSuccessful()) {
            return null;
        }

        return $this->filter($process->getOutput(), $node);
    }

    public function filter(string $output, ?string $node): ?string
    {
        $lines = preg_split('/\R/', $output) ?: [];
        $nodeName = $node ? basename($node) : null;

        $contextLines = [];
        $hasUsbError = false;

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            if ($this->matchesAny($line, self::ERROR_PATTERNS)) {
                $hasUsbError = true;
            }

            if (
                $this->matchesAny($line, self::CONTEXT_PATTERNS)
                || ($nodeName && str_contains($line, $nodeName))
            ) {
                $contextLines[] = $line;
            }
        }

        if (! $hasUsbError || ! $contextLines) {
            return null;
        }

        $diagnostic = implode(PHP_EOL, array_slice($contextLines, -self::MAX_LINES));

        if (strlen($diagnostic) > self::MAX_CHARS) {
            $diagnostic = substr($diagnostic, -self::MAX_CHARS);
        }

        return trim($diagnostic) ?: null;
    }

    private function matchesAny(string $line, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $line)) {
                return true;
            }
        }

        return false;
    }
}
