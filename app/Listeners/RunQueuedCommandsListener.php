<?php

namespace App\Listeners;

use App\Events\CommandQueued;
use App\Exceptions\InitializationException;
use App\Libraries\Serial;
use App\Models\Printer;
use App\Plugins\PluginHookCompiler;
use Exception;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class RunQueuedCommandsListener implements ShouldQueue
{
    private const QUEUED_COMMAND_LOCK_TTL = 60 * 15;

    public function handle(CommandQueued $event): void
    {
        $log = Log::channel('queued-commands-listener');

        $log->debug(__METHOD__.': event: '.json_encode($event));

        $printer = Printer::select('node', 'baudRate', 'queuedCommands')->find($event->printerId);

        if (! $printer) {
            throw new InitializationException('no such printer');
        }

        if (! $printer->node) {
            throw new InitializationException('this printer doesn\'t have a node assigned.');
        }

        tryToWaitForMapper($log);

        $lock = Cache::lock(
            $printer->_id.'_queuedCommandLock',
            self::QUEUED_COMMAND_LOCK_TTL
        );

        try {
            $lock->block(self::QUEUED_COMMAND_LOCK_TTL);
        } catch (LockTimeoutException $exception) {
            $log->warning(
                $printer->node.': queued command listener timed out waiting for access: '.$exception->getMessage()
            );

            return;
        }

        $serial = null;

        try {
            $serial = new Serial(
                fileName: $printer->node,
                baudRate: $printer->baudRate,
                printerId: $printer->_id,
                pluginHooks: app(PluginHookCompiler::class)->compileSerialHooks()
            );

            $queuedCommands = $printer->getResetQueuedCommands();

            foreach ($queuedCommands as $command) {
                $log->info($printer->node.': PROCESSED: '.$serial->query($command));
            }

            $printer->updateLastSeen();
        } catch (Exception $exception) {
            $printer->setLastError($exception->getMessage());

            $log->error(
                $printer->node.': connection failed: '.$exception->getMessage().PHP_EOL.
                PHP_EOL.
                $exception->getTraceAsString()
            );
        } finally {
            $serial?->close();
            $this->releaseLock($lock);
        }
    }

    private function releaseLock(?Lock $lock): void
    {
        try {
            $lock?->release();
        } catch (Exception) {
            // Lock release failures should not crash the queued command worker.
        }
    }
}
