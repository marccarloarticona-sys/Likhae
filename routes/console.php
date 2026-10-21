<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

$recordSchedulerHeartbeat = static function (string $signature, string $outcome): Closure {
    return static function () use ($signature, $outcome): void {
        try {
            $prefix = rtrim((string) config('system_monitoring.scheduler_cache_prefix', 'likhae:system-health:scheduler'), ':');
            $key = $prefix.':'.str_replace(':', '-', $signature).':'.$outcome;
            $retentionDays = max(1, min(90, (int) config('system_monitoring.scheduler_heartbeat_retention_days', 30)));

            Cache::store()->put($key, now()->toIso8601String(), now()->addDays($retentionDays));
        } catch (Throwable) {
            // A heartbeat write must not change the outcome of the scheduled business command.
        }
    };
};

Schedule::command('likhae:publish-announcements')
    ->everyMinute()
    ->timezone('Asia/Manila')
    ->withoutOverlapping()
    ->onSuccess($recordSchedulerHeartbeat('likhae:publish-announcements', 'success'))
    ->onFailure($recordSchedulerHeartbeat('likhae:publish-announcements', 'failure'));
Schedule::command('likhae:purge-expired-soft-deletes')
    ->daily()
    ->timezone('Asia/Manila')
    ->withoutOverlapping()
    ->onSuccess($recordSchedulerHeartbeat('likhae:purge-expired-soft-deletes', 'success'))
    ->onFailure($recordSchedulerHeartbeat('likhae:purge-expired-soft-deletes', 'failure'));
Schedule::command('likhae:auto-confirm-delivered-orders')
    ->hourly()
    ->timezone('Asia/Manila')
    ->withoutOverlapping()
    ->onSuccess($recordSchedulerHeartbeat('likhae:auto-confirm-delivered-orders', 'success'))
    ->onFailure($recordSchedulerHeartbeat('likhae:auto-confirm-delivered-orders', 'failure'));
Schedule::command('likhae:expire-rider-assignment-offers')
    ->everyMinute()
    ->timezone('Asia/Manila')
    ->withoutOverlapping()
    ->onSuccess($recordSchedulerHeartbeat('likhae:expire-rider-assignment-offers', 'success'))
    ->onFailure($recordSchedulerHeartbeat('likhae:expire-rider-assignment-offers', 'failure'));
Schedule::command('likhae:expire-account-suspensions')
    ->everyMinute()
    ->timezone('Asia/Manila')
    ->withoutOverlapping()
    ->onSuccess($recordSchedulerHeartbeat('likhae:expire-account-suspensions', 'success'))
    ->onFailure($recordSchedulerHeartbeat('likhae:expire-account-suspensions', 'failure'));
