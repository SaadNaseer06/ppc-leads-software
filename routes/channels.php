<?php

use App\Enums\Role;
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

// A sales rep's own inbox feed: chats whose lead is assigned to them
Broadcast::channel('chats.user.{userId}', function ($user, string $userId) {
    return (string) $user->id === $userId;
});

// Brand-wide inbox feeds carry every message, so only people who see every chat of the brand
// may listen; sales reps follow their own chats through chats.user.{id}
Broadcast::channel('chats.{brandId}', function ($user, string $brandId) {
    return ctype_digit($brandId) && $user->canAssignLeads() && $user->canAccessBrand((int) $brandId);
});

// "Lead counts changed" pings (no data): global users, a brand's admins and finance, and
// each assignee for their own badges
Broadcast::channel('leads.all', function ($user) {
    return $user->hasGlobalAccess();
});

Broadcast::channel('leads.brand.{brandId}', function ($user, string $brandId) {
    return ctype_digit($brandId) && in_array($user->role, [Role::Admin, Role::Finance], true) && $user->canAccessBrand((int) $brandId);
});

Broadcast::channel('leads.user.{userId}', function ($user, string $userId) {
    return (string) $user->id === $userId;
});
