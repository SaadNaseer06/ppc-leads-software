<?php

use App\Models\Conversation;
use Illuminate\Support\Facades\Broadcast;

/*
| Dashboard users: authorize private chat channels via Sanctum session.
| Visitors authenticate separately through POST /api/chat/auth (visitor token).
*/

Broadcast::channel('chat.{conversationId}', function ($user, int $conversationId) {
    return Conversation::query()->visibleTo($user)->whereKey($conversationId)->exists();
});

Broadcast::channel('chats.{brandId}', function ($user, int $brandId) {
    return $user->hasGlobalAccess() || $user->canAccessBrand($brandId);
});
