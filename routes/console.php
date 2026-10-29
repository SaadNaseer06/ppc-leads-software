<?php

use Illuminate\Support\Facades\Schedule;

// Runs from one cPanel cron job: * * * * * php /path/to/artisan schedule:run
Schedule::command('model:prune')->daily()->at('03:15');
