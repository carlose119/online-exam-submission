<?php

use App\Services\ReportScheduleService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('reports:dispatch-scheduled', function (ReportScheduleService $reports): void {
    $reports->dispatchDue();
});
Schedule::command('reports:dispatch-scheduled')->everyMinute()->withoutOverlapping();
