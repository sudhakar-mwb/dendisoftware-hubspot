<?php

namespace App\Console;

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
        $schedule->command('get:syncHSContactToDendi')->everyTwoMinutes();
        $schedule->command('get:createdOrderSyncDendiToHubspot')->everyTwoMinutes();
        $schedule->command('get:updatedOrderSyncDendiToHubspot')->everyFiveMinutes();
        $schedule->command('get:fetchHSPropertyValues')->daily();  // daily
        $schedule->command('get:syncProviderAndAccountToHubspot')->daily();  // daily
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
