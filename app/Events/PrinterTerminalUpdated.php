<?php

namespace App\Events;

use App\Enums\Marlin;

use App\Models\Printer;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;

use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

use Illuminate\Foundation\Events\Dispatchable;

class PrinterTerminalUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public $queue = 'broadcasts';

    public string   $printerId;
    public string   $dateString;
    public string   $command;
    public ?string  $meaning = null;
    public ?int     $line;
    public ?int     $maxLine;
    public bool     $running;

    /**
     * Create a new event instance.
     *
     * @return void
     */
    public function __construct(string $printerId, string $command, ?int $line = null, ?int $maxLine = null, ?int $terminalMaxLines = null)
    {
        $this->printerId    = $printerId;
        $this->dateString   = nowHuman();
        $this->command      = $command;
        $this->line         = $line;
        $this->maxLine      = $maxLine;
        $this->running      = Printer::getRunningStatusOf( $printerId );

        $terminal = Printer::getConsoleOf( $this->printerId );

        if (!$terminal) {
            $terminal = '';
        }

        foreach (explode(PHP_EOL, $command) as $line) {
            if ($line = trim( $line )) {
                if (str_starts_with($line, '>')) { // input
                    $this->meaning = Marlin::getLabel(
                        str_replace('> ', '', $line)
                    );
                } else { // output
                    if (strpos($line, Printer::MARLIN_TEMPERATURE_INDICATOR) !== false) { // with temperature data
                        Printer::setStatisticsOf( $this->printerId, $line, 0);

                        Printer::updateLastSeenOf( $this->printerId );

                        PrinterConnectionStatusUpdated::dispatch( $this->printerId );
                    }
                }

                $line = $this->dateString . ': ' . $line;

                $terminal .= $line . PHP_EOL;
            }
        }

        if ($terminalMaxLines) {
            while (substr_count($terminal, PHP_EOL) > $terminalMaxLines) {
                $terminal = substr(
                    string: $terminal,
                    offset: strpos($terminal, PHP_EOL) + 1
                );
            }
        }

        Printer::setConsoleOf( $this->printerId, $terminal );
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return \Illuminate\Broadcasting\Channel|array
     */
    public function broadcastOn()
    {
        return new PrivateChannel('console.' . $this->printerId);
    }
}
