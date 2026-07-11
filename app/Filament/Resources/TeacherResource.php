<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TeacherResource\Pages\CreateTeacher;
use App\Filament\Resources\TeacherResource\Pages\EditTeacher;
use App\Filament\Resources\TeacherResource\Pages\ListTeacher;
use App\Models\User;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class TeacherResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-academic-cap';

    protected static ?string $navigationLabel = 'Teachers';

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getEloquentQuery()->where('role', 'TEACHER');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('email')
                    ->email()
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),
                TextInput::make('password')
                    ->password()
                    ->revealable()
                    ->required(fn (string $context): bool => $context === 'create')
                    ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? Hash::make($state) : null)
                    ->dehydrated(fn (?string $state): bool => filled($state)),
                Hidden::make('role')
                    ->default('TEACHER'),
                Toggle::make('is_suspended')
                    ->label('Suspended')
                    ->formatStateUsing(fn (?User $record): bool => $record !== null && $record->suspended_at !== null)
                    ->dehydrateStateUsing(function (bool $state, ?User $record): ?string {
                        if ($record === null) {
                            return null;
                        }

                        if ($state && $record->suspended_at === null) {
                            return now();
                        }

                        if (! $state && $record->suspended_at !== null) {
                            return null;
                        }

                        return $record->suspended_at;
                    })
                    ->afterStateHydrated(function (Toggle $component, ?User $record): void {
                        if ($record !== null && $record->suspended_at !== null) {
                            $component->state(true);
                        }
                    }),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->searchable()
                    ->sortable(),
                IconColumn::make('suspended_at')
                    ->label('Suspended')
                    ->boolean()
                    ->state(fn (User $record): bool => $record->suspended_at !== null)
                    ->trueIcon('heroicon-o-x-circle')
                    ->falseIcon('heroicon-o-check-circle')
                    ->trueColor('danger')
                    ->falseColor('success'),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                // Future: filter by suspended status
            ])
            ->actions([
                EditAction::make(),
                Action::make('toggleSuspend')
                    ->label(fn (User $record): string => $record->suspended_at === null ? 'Suspend' : 'Reactivate')
                    ->icon(fn (User $record): string => $record->suspended_at === null ? 'heroicon-o-lock-closed' : 'heroicon-o-lock-open')
                    ->color(fn (User $record): string => $record->suspended_at === null ? 'warning' : 'success')
                    ->requiresConfirmation()
                    ->action(function (User $record): void {
                        $record->suspended_at = $record->suspended_at === null ? now() : null;
                        $record->save();

                        $label = $record->suspended_at === null ? 'reactivated' : 'suspended';

                        Notification::make()
                            ->title('Teacher '.$label)
                            ->success()
                            ->send();
                    }),
                Action::make('generateTempPassword')
                    ->label('Generate Temp Password')
                    ->icon('heroicon-o-key')
                    ->color('gray')
                    ->action(function (User $record): void {
                        $plain = Str::random(16);
                        $record->password = Hash::make($plain);
                        $record->save();

                        Notification::make()
                            ->title('Temporary password generated')
                            ->body("The temporary password is: **{$plain}**\n\nCopy it now — it will not be shown again.")
                            ->success()
                            ->persistent()
                            ->send();
                    }),
                DeleteAction::make(),
            ])
            ->bulkActions([
                // Future: bulk suspend/reactivate
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTeacher::route('/'),
            'create' => CreateTeacher::route('/create'),
            'edit' => EditTeacher::route('/{record}/edit'),
        ];
    }
}
