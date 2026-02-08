<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Other schedules are registered by service providers
        // (e.g., ScheduleIntegrationServiceProvider for scheduled triggers)
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');
        $this->load(__DIR__.'/Commands/Research');
        require base_path('routes/console.php');
    }
}

// Register the PromptlyAgentRephraseCommand
if (class_exists(\App\Console\Commands\PromptlyAgentRephraseCommand::class)) {
    Artisan::starting(function ($artisan) {
        $artisan->resolve(\App\Console\Commands\PromptlyAgentRephraseCommand::class);
    });
}
