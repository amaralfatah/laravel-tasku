<?php

use Illuminate\Support\Facades\Schedule;

// Daily due reminders, early enough to be waiting when the team starts (6.14).
Schedule::command('notifications:due-soon')
    ->dailyAt('07:00')
    ->timezone('Asia/Jakarta');

// Retention window for in-app notifications (NTF-6).
Schedule::command('notifications:prune')
    ->dailyAt('01:00')
    ->timezone('Asia/Jakarta');
