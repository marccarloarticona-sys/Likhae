<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('likhae:publish-announcements')
    ->everyMinute()
    ->timezone('Asia/Manila')
    ->withoutOverlapping();
Schedule::command('likhae:purge-expired-soft-deletes')
    ->daily()
    ->timezone('Asia/Manila')
    ->withoutOverlapping();
Schedule::command('likhae:auto-confirm-delivered-orders')
    ->hourly()
    ->timezone('Asia/Manila')
    ->withoutOverlapping();
Schedule::command('likhae:expire-rider-assignment-offers')
    ->everyMinute()
    ->timezone('Asia/Manila')
    ->withoutOverlapping();
