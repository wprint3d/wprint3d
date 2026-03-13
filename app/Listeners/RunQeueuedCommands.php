<?php

namespace App\Listeners;

use App\Events\CommandQueued;
use App\Exceptions\InitializationException;
use App\Libraries\Serial;
use App\Models\Printer;
use App\Plugins\PluginHookCompiler;
use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class RunQeueuedCommands implements ShouldQueue
{
    /**
     * Create the event listener.
     *
     * @return void
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     *
     * @return void
     */
    public function handle(CommandQueued $event)
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

        $serial = new Serial(
            fileName: $printer->node,
            baudRate: $printer->baudRate,
            printerId: $printer->_id,
            pluginHooks: app(PluginHookCompiler::class)->compileSerialHooks()
        );

        try {
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
        }
    }
}
