<?php

namespace App\Support;

final class Realtime
{
    /**
     * Public Pusher settings for browsers, or null when this server doesn't broadcast
     * through Pusher: connecting anyway would only wait for events that never come.
     *
     * @return array{key: string, cluster: string}|null
     */
    public static function config(): ?array
    {
        $key = config('broadcasting.connections.pusher.key');
        if (config('broadcasting.default') !== 'pusher' || ! $key) {
            return null;
        }

        return ['key' => $key, 'cluster' => config('broadcasting.connections.pusher.options.cluster') ?: 'mt1'];
    }
}
