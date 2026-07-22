<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('notificaciones:globales')
    ->dailyAt(config('services.notificaciones.globales_hora', '07:00'))
    ->timezone(config('app.timezone', 'America/Guayaquil'));

Schedule::command('notificaciones:cumpleanos')
    ->everyMinute()
    ->timezone(config('app.timezone', 'America/Guayaquil'));

Schedule::command('notificaciones:despachar-push --limit=100')
    ->everyMinute()
    ->timezone(config('app.timezone', 'America/Guayaquil'));
