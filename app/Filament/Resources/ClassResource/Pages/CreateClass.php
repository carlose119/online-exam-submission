<?php

namespace App\Filament\Resources\ClassResource\Pages;

use App\Filament\Resources\ClassResource;
use App\Models\SchoolClass;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Str;

class CreateClass extends CreateRecord
{
    protected static string $resource = ClassResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['teacher_id'] = auth()->id();

        $attempts = 0;
        do {
            $code = Str::random(8);
            if (! SchoolClass::where('invitation_code', $code)->exists()) {
                break;
            }
            $attempts++;
        } while ($attempts < 5);

        if ($attempts >= 5) {
            throw new \RuntimeException('Could not generate a unique invitation code after 5 attempts.');
        }

        $data['invitation_code'] = $code;

        return $data;
    }
}
