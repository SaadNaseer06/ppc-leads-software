<?php

use App\Models\Conversation;
use Illuminate\Support\Facades\Broadcast;

/*
| Dashboard users: authorize private chat channels via Sanctum session.
| Visitors authenticate separately through POST /api/chat/auth (visitor token).
*/

Broadcast::channel('chat.{conversationId}', function ($user, string $conversationId) {
    return ctype_digit($conversationId) && Conversation::query()->visibleTo($user)->whereKey((int) $conversationId)->exists();
});

// Registered before chats.{brandId}, which would also match it (the first matching pattern decides)
Broadcast::channel('chats.all', function ($user) {
    return $user->hasGlobalAccess();
});

// Brand-wide inbox feeds carry every message, so only people who see every chat of the brand
// may listen; sales reps follow their own conversations through chat.{id}
Broadcast::channel('chats.{brandId}', function ($user, string $brandId) {
    return ctype_digit($brandId) && $user->canAssignLeads() && $user->canAccessBrand((int) $brandId);
});
