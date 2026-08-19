<?php

namespace App\Filament\Resources\ReportScheduleResource\Pages;

use App\Filament\Resources\ReportScheduleResource;
use App\Services\ReportScheduleService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateReportSchedule extends CreateRecord
{
    protected static string $resource = ReportScheduleResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        [$classId, $input] = ReportScheduleResource::input($data);

        return app(ReportScheduleService::class)->create(ReportScheduleResource::actor(), $classId, $input);
    }
}
