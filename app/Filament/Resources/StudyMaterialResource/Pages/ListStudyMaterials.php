<?php

namespace App\Filament\Resources\StudyMaterialResource\Pages;

use App\Filament\Resources\StudyMaterialResource;
use App\Services\StudyMaterialStorageQuota;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Auth;

class ListStudyMaterials extends ListRecords
{
    protected static string $resource = StudyMaterialResource::class;

    public function getSubheading(): string
    {
        return app(StudyMaterialStorageQuota::class)->summary((int) Auth::id());
    }
}
