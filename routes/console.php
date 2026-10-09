<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Schedule automated tasks. Reminder jobs run after statuses are refreshed.
Schedule::command('contracts:update-expired')->dailyAt('06:00');
Schedule::command('payments:update-overdue-status')->dailyAt('07:00');
Schedule::command('payments:calculate-overdue-interest')->dailyAt('07:30');
Schedule::command('payments:send-reminders')->dailyAt('08:00');
Schedule::command('contracts:send-renewal-notifications')->dailyAt('09:00');
Schedule::command('contracts:send-expiry-notifications')->dailyAt('10:00');
Schedule::command('sms:refresh-statuses')->everyFifteenMinutes()->withoutOverlapping();
