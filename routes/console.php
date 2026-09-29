<?php

use App\Models\WorkspaceMember;
use App\Services\Chat\OperatorAssigner;
use Illuminate\Support\Facades\Schedule;

Schedule::command('billing:renew')->hourly()->withoutOverlapping();
Schedule::command('billing:remind')->dailyAt('09:00')->withoutOverlapping();
Schedule::command('sla:check')->everyMinute()->withoutOverlapping();

// Operators whose browser stopped sending heartbeats are marked offline.
Schedule::call(function () {
    WorkspaceMember::where('is_online', true)
        ->where('last_seen_at', '<', now()->subSeconds(OperatorAssigner::ONLINE_TTL_SECONDS))
        ->update(['is_online' => false]);
})->everyMinute()->name('presence:sweep');

Schedule::command('queue:prune-failed --hours=168')->daily();
