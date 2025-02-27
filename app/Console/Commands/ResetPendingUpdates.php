<?php

namespace App\Console\Commands;

use App\Exceptions\InitializationException;
use App\Models\Meta;
use Illuminate\Console\Command;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

use Symfony\Component\Yaml\Yaml;

class ResetPendingUpdates extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:reset-pending-updates';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reset pending updates';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        Meta::pendingUpdate()->delete();

        return Command::SUCCESS;
    }
}
