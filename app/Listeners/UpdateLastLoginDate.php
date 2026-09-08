<?php

namespace App\Listeners;

use Illuminate\Auth\Events\Login;

class UpdateLastLoginDate
{
    public function handle(Login $event): void
    {
        $user = $event->user;
        $usesTimestamps = $user->timestamps;

        $user->timestamps = false;

        try {
            $user->forceFill([
                'last_login_at' => now(),
            ])->saveQuietly();
        } finally {
            $user->timestamps = $usesTimestamps;
        }
    }
}
