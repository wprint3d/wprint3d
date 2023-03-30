<?php

namespace App\Events;

use App\Enums\BackupInterval;
use App\Models\Configuration;
use App\Models\Printer;

use Exception;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;

use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

use Illuminate\Foundation\Events\Dispatchable;

use Illuminate\Queue\SerializesModels;

class PrintJobFailed implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $queue = 'broadcasts';

    public string $printerId;
    public string $activeFile;
    public ?int   $lastLine;
    public ?int   $jobBackupInterval;

    public function __construct(string $printerId)
    {
        $printer = Printer::select('lastSeen', 'activeFile', 'lastLine')->find( $printerId );

        if (!$printer) throw new Exception('No such printer.');

        $this->printerId  = $printer->_id;
        $this->activeFile = $printer->activeFile;
        $this->lastLine   = $printer->lastLine;
        $this->jobBackupInterval = Configuration::get('jobBackupInterval', BackupInterval::fromKey( env('JOB_BACKUP_INTERVAL') )->value);
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return \Illuminate\Broadcasting\Channel|array
     */
    public function broadcastOn()
    {
        return new PrivateChannel('failed-job.' . $this->printerId);
    }
}
