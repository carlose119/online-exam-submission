<?php

namespace App\Filament\Resources\ClassResource\Pages;

use App\Filament\Resources\ClassResource;
use App\Models\SchoolClass;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Str;

class EditClass extends EditRecord
{
    protected static string $resource = ClassResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('copyInvitationLink')
                ->label('Copy invitation link')
                ->icon('heroicon-o-clipboard')
                ->color('gray')
                ->action(function (SchoolClass $record): void {
                    $url = route('class.join.show', $record->invitation_code);

                    Notification::make()
                        ->title('Invitation link copied!')
                        ->body("URL: {$url}")
                        ->success()
                        ->persistent()
                        ->send();

                    $this->js(<<<JS
                        navigator.clipboard.writeText('{$url}').then(() => {
                            console.log('Invitation link copied to clipboard');
                        }).catch(() => {
                            console.warn('Clipboard copy failed');
                        });
                    JS);
                }),

            Action::make('regenerateInvitationCode')
                ->label('Regenerate invitation code')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->requiresConfirmation()
                ->action(function (SchoolClass $record): void {
                    $oldCode = $record->invitation_code;

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

                    $record->invitation_code = $code;
                    $record->save();

                    Notification::make()
                        ->title('Invitation code regenerated')
                        ->body("Old: {$oldCode} → New: {$code}")
                        ->success()
                        ->persistent()
                        ->send();
                }),
        ];
    }
}
