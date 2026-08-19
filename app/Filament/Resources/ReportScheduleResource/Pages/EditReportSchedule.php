<?php

namespace App\Filament\Resources\ReportScheduleResource\Pages;

use App\Filament\Resources\ReportScheduleResource;
use App\Services\ReportScheduleService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditReportSchedule extends EditRecord
{
    protected static string $resource = ReportScheduleResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        [$classId, $input] = ReportScheduleResource::input($data);

        return app(ReportScheduleService::class)->update(
            ReportScheduleResource::actor(), (int) $record->getKey(), $classId, $input,
        );
    }
}
