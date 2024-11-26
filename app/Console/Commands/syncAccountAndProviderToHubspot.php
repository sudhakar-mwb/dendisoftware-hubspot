<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class syncAccountAndProviderToHubspot extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'get:syncAccountAndProviderToHubspot';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // fetch account And associated providers sync to hubspot
    }
}
