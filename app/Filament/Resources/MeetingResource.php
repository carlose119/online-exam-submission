<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MeetingResource\Pages\CreateMeeting;
use App\Filament\Resources\MeetingResource\Pages\EditMeeting;
use App\Filament\Resources\MeetingResource\Pages\ListMeetings;
use App\Filament\Resources\MeetingResource\Pages\ViewMeeting;
use App\Models\Meeting;
use App\Models\SchoolClass;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class MeetingResource extends Resource
{
    protected static ?string $model = Meeting::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-video-camera';

    protected static ?string $navigationLabel = 'Meetings';

    /**
     * Scope: TEACHER sees meetings of their own classes; ADMIN sees all meetings.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->when(Auth::user()?->role !== 'ADMIN', function (Builder $query): void {
                $query->whereHas('classroom', fn ($q) => $q->where('teacher_id', Auth::id()));
            });
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Select::make('class_id')
                    ->label('Class')
                    ->options(fn () => SchoolClass::when(Auth::user()?->role !== 'ADMIN', fn ($q) => $q->where('teacher_id', Auth::id()))->pluck('title', 'id')->toArray())
                    ->searchable()
                    ->required(),

                TextInput::make('title')
                    ->required()
                    ->maxLength(255),

                DateTimePicker::make('scheduled_at')
                    ->live()
                    ->label('Scheduled At')
                    ->required(),

                TextInput::make('duration_minutes')
                    ->label('Duration')
                    ->numeric()
                    ->suffix('min')
                    ->minValue(1)
                    ->default(60),

                TextInput::make('meeting_url')
                    ->label('Meeting URL')
                    ->url()
                    ->nullable(),

                RichEditor::make('agenda')
                    ->label('Agenda')
                    ->nullable()
                    ->columnSpanFull(),

                Toggle::make('is_recurring')
                    ->visibleOn('create')
                    ->label('Is recurring?')
                    ->live()
                    ->default(false),

                Section::make('Make this recurring')
                    ->columnSpanFull()
                    ->columns(3)
                    ->visible(fn (Get $get, string $operation): bool => $operation === 'create' && (bool) $get('is_recurring'))
                    ->schema([
                        Select::make('frequency')
                            ->live()
                            ->options([
                                'weekly' => 'Weekly',
                                'biweekly' => 'Biweekly',
                                'monthly' => 'Monthly',
                            ])
                            ->default('weekly')
                            ->required(),

                        Select::make('days_of_week')
                            ->label('Weekdays')
                            ->helperText('Leave blank to repeat the start weekday. Selecting days may move the first meeting forward; the preview includes every instance.')
                            ->multiple()
                            ->live()
                            ->options([1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'])
                            ->visible(fn (Get $get): bool => in_array($get('frequency'), ['weekly', 'biweekly'], true))
                            ->rule('array')
                            ->rule('max:7')
                            ->rule('distinct'),

                        TextInput::make('interval')
                            ->live()
                            ->numeric()
                            ->default(1)
                            ->minValue(1)
                            ->required(),

                        TextInput::make('count')
                            ->live()
                            ->label('Number of instances')
                            ->numeric()
                            ->default(12)
                            ->minValue(1)
                            ->maxValue(52)
                            ->required()
                            ->helperText('Total instances including the first one.'),

                        Placeholder::make('occurrence_preview')
                            ->label('Occurrence dates')
                            ->content(fn (Get $get): string => implode(', ', static::previewDates($get)))
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    private static function previewDates(Get $get): array
    {
        return static::occurrencePreview([
            'is_recurring' => $get('is_recurring'),
            'scheduled_at' => $get('scheduled_at'),
            'frequency' => $get('frequency'),
            'interval' => $get('interval'),
            'count' => $get('count'),
            'days_of_week' => $get('days_of_week'),
        ]);
    }

    public static function occurrencePreview(array $data): array
    {
        if (! ($data['is_recurring'] ?? false) || empty($data['scheduled_at']) ||
            ! is_numeric($data['count'] ?? null) || (int) $data['count'] < 1 || (int) $data['count'] > 52 ||
            ! is_numeric($data['interval'] ?? null) || (int) $data['interval'] < 1) {
            return [];
        }

        try {
            $start = Carbon::parse($data['scheduled_at']);
        } catch (\Exception) {
            return [];
        }

        return array_map(
            fn (Carbon $date) => $date->format('Y-m-d H:i:s'),
            Meeting::occurrenceDates($start, [
                'frequency' => $data['frequency'] ?? 'weekly',
                'interval' => $data['interval'],
                'days_of_week' => $data['days_of_week'] ?? null,
            ], (int) $data['count'])
        );
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('classroom.title')
                    ->label('Class')
                    ->searchable(),

                TextColumn::make('scheduled_at')
                    ->label('Scheduled')
                    ->dateTime()
                    ->sortable()
                    ->badge()
                    ->color(fn ($state): string => $state && $state < now() ? 'gray' : 'success'),

                BadgeColumn::make('duration_minutes')
                    ->label('Duration')
                    ->formatStateUsing(fn (int $state): string => "{$state} min"),

                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('scheduled_at', 'asc')
            ->filters([
                //
            ])
            ->actions([
                Action::make('join')
                    ->label('Join')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->color('success')
                    ->url(fn (Meeting $record): ?string => $record->meeting_url)
                    ->openUrlInNewTab()
                    ->visible(fn (Meeting $record): bool => $record->isLive()),

                Action::make('view')
                    ->label('View')
                    ->icon('heroicon-o-eye')
                    ->url(fn (Meeting $record): string => static::getUrl('view', ['record' => $record])),

                EditAction::make(),

                DeleteAction::make()
                    ->modalDescription('This meeting will be permanently deleted.'),
            ])
            ->bulkActions([
                //
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMeetings::route('/'),
            'create' => CreateMeeting::route('/create'),
            'edit' => EditMeeting::route('/{record}/edit'),
            'view' => ViewMeeting::route('/{record}/view'),
        ];
    }
}
