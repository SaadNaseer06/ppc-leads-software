<?php

use App\Services\NativeChatService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('chats:close-idle', function (NativeChatService $chats) {
    $this->info('Closed '.$chats->closeIdle().' idle chat(s).');
})->purpose('End live chats with no messages for ppc.active_chat_hours');

// Runs from one cPanel cron job: * * * * * php /path/to/artisan schedule:run
Schedule::command('model:prune')->daily()->at('03:15');
Schedule::command('chats:close-idle')->everyTenMinutes();
