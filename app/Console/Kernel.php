<?php

namespace App\Console;

use App\Models\Configuration;

use Spatie\ShortSchedule\ShortSchedule;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        $schedule->command('refresh:device-variants')->daily();
    }
    
    /**
     * Define the application's command fast-paced schedule.
     *
     * @param  mixed $shortSchedule
     * @return void
     */
    protected function shortSchedule(ShortSchedule $shortSchedule)
    {
        // Do nothing for now
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
