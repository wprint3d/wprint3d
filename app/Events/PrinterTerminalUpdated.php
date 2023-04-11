<?php

namespace App\Events;

use App\Enums\Marlin;

use App\Models\Printer;

use Illuminate\Support\Str;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;

use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

use Illuminate\Foundation\Events\Dispatchable;

use Illuminate\Queue\SerializesModels;

class PrinterTerminalUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

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
    public function __construct(string $printerId, string $dateString, string $command, ?int $line = null, ?int $maxLine = null, bool $running = true)
    {
        $this->printerId    = $printerId;
        $this->dateString   = $dateString;
        $this->command      = $command;
        $this->line         = $line;
        $this->maxLine      = $maxLine;
        $this->running      = Printer::getRunningStatusOf( $printerId );

        $cleanedUpCommand = Str::of( $command )->trim();

        if ($cleanedUpCommand->startsWith('>')) {
            $this->meaning = Marlin::getLabel(
                $cleanedUpCommand->replace('> ', '')->toString()
            );
        } else if ($cleanedUpCommand->contains( Printer::MARLIN_TEMPERATURE_INDICATOR )) {
            Printer::setStatisticsOf( $this->printerId, $cleanedUpCommand, 0);

            PrinterConnectionStatusUpdated::dispatch( $this->printerId );
        }
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
