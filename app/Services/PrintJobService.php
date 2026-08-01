<?php

namespace App\Services;

use App\Events\SystemMessage;
use App\Exceptions\PrintJobException;
use App\Jobs\PrintGcode;
use App\Libraries\Serial;
use App\Models\Printer;
use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class PrintJobService
{
    public function start(User $owner, Printer $printer, string $fileName, bool $alreadyLocked = false): void
    {
        $operation = fn () => $this->startUnlocked($owner, $printer, $fileName);

        if ($alreadyLocked) {
            $operation();

            return;
        }

        $this->withPrinterLock($printer, $operation);
    }

    public function pause(Printer $printer): void
    {
        $this->withPrinterLock($printer, function () use ($printer) {
            $this->ensureConnected($printer);
            $this->ensureActiveJob($printer);
            $printer->pause();
        });
    }

    public function resume(Printer $printer): void
    {
        $this->withPrinterLock($printer, function () use ($printer) {
            $this->ensureConnected($printer);
            $this->ensureActiveJob($printer);
            $printer->resume();
        });
    }

    public function cancel(Printer $printer): void
    {
        $this->withPrinterLock($printer, function () use ($printer) {
            $this->ensureConnected($printer);
            $this->ensureActiveJob($printer);

            $printer->hasActiveJob = false;
            $printer->activeFile = null;
            $printer->save();

            SystemMessage::send('refreshActiveFile');
        });
    }

    public function withPrinterLock(Printer $printer, callable $operation): mixed
    {
        $uuid = data_get($printer, 'machine.uuid', (string) $printer->_id);

        try {
            return Cache::store('redis')->lock('printer-job:'.$uuid, 15)->block(5, $operation);
        } catch (LockTimeoutException) {
            throw new PrintJobException('busy', 'The printer is busy handling another request.');
        }
    }

    private function startUnlocked(User $owner, Printer $printer, string $fileName): void
    {
        if (! Storage::disk('gcode')->exists($fileName)) {
            throw new PrintJobException('file_not_found', __('server.files.not_found'));
        }

        $this->ensureConnected($printer);

        if ($printer->hasActivePrintJob()) {
            throw new PrintJobException('busy', __('server.printers.active_file_already_present'));
        }

        if ($printer->hasPendingPrintRecovery()) {
            throw new PrintJobException(
                'recovery_pending',
                'The previous failed print must be recovered or dismissed before starting another print.'
            );
        }

        $printer->resume();
        $printer->hasActiveJob = true;
        $printer->activeFile = $fileName;
        $printer->save();

        $printer->setCurrentLine(0);
        $printer->setCurrentLayer(0);

        PrintGcode::dispatch($owner, $printer->_id);
    }

    private function ensureConnected(Printer $printer): void
    {
        if ($printer->connected && Serial::nodeExists($printer->node)) {
            return;
        }

        if ($printer->connected) {
            $printer->connected = false;
            $printer->setConnectionStatus(Printer::CONNECTION_STATUS_OFFLINE);
            $printer->save();
        }

        throw new PrintJobException('offline', __('server.printers.not_connected'));
    }

    private function ensureActiveJob(Printer $printer): void
    {
        if (! $printer->hasActivePrintJob()) {
            throw new PrintJobException('no_active_job', __('server.printers.no_active_file'));
        }
    }
}
