<?php

namespace App\Console\Commands;

use App\Models\User;

use Illuminate\Console\Command;

class ResetUsers extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'reset:users';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Truncate the users collection';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        User::truncate();

        return Command::SUCCESS;
    }
}
