<?php

namespace App\Events;

use App\Models\Printer;
use App\Services\PrintExecutionState;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;

use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

use Illuminate\Foundation\Events\Dispatchable;

class PrinterConnectionStatusUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public $queue = 'broadcasts';

    public string $printerId;
    public  array $statistics;
    public  mixed $lastSeen;
    public   bool $isPrinting    = false;
    public   bool $isPaused      = false;
    public   bool $isReconnecting = false;
    public   ?int $layer         = null;
    public   ?int $maxLayer      = null;
    public   ?int $thresholdSecs = null;
    public string $connectionStatus;
    public ?string $connectionDiagnostic;
    public int $currentLine;
    public int $maxLine;
    public array $absolutePosition;

    public function __construct(
        string $printerId,
          ?int $lastSeen      = null,
        ?array $statistics    = null,
          bool $hasActiveFile = false,
          bool $isPaused      = false,
          ?int $thresholdSecs = null,
       ?string $connectionStatus = null,
       ?string $connectionDiagnostic = null
    ) {
        $this->printerId = $printerId;
        $this->isPaused  = $isPaused;
        $this->isReconnecting = app(PrintExecutionState::class)->isReconnecting($printerId);

        $this->statistics =
            $statistics === null
                ? Printer::getStatisticsOf($printerId)
                : $statistics;

        $this->lastSeen   =
            $lastSeen === null
                ? Printer::getLastSeenOf($printerId)
                : $lastSeen;

        if ($hasActiveFile) {
            $this->isPrinting = true;
        }

        if ($this->isPrinting) {
            $this->layer    = Printer::getCurrentLayerOf($printerId);
            $this->maxLayer = Printer::getMaxLayerOf($printerId);
        }

        $this->thresholdSecs = $thresholdSecs;
        $this->connectionStatus =
            $connectionStatus === null
                ? Printer::getConnectionStatusOf($printerId)
                : $connectionStatus;
        $this->connectionDiagnostic =
            $connectionDiagnostic === null
                ? Printer::getConnectionDiagnosticOf($printerId)
                : $connectionDiagnostic;
        $this->currentLine = Printer::getCurrentLineOf($printerId);
        $this->maxLine = Printer::getMaxLineOf($printerId);
        $this->absolutePosition = Printer::getAbsolutePositionOf($printerId);
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return \Illuminate\Broadcasting\Channel|array
     */
    public function broadcastOn()
    {
        return new PrivateChannel('connection-status.' . $this->printerId);
    }
}
