<?php

namespace App\Console;

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
        $shortSchedule->command('printers:handle-auto-serial')->everySeconds(
            env('PRINTER_AUTO_SERIAL_INTERVAL_SECS')
        )->withoutOverlapping();
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
