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
use Filament\Forms\Components\RichEditor;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
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
                    ->label('Is recurring?')
                    ->live()
                    ->default(false),

                Section::make('Make this recurring')
                    ->columns(3)
                    ->visible(fn (Get $get): bool => (bool) $get('is_recurring'))
                    ->schema([
                        Select::make('frequency')
                            ->options([
                                'weekly'   => 'Weekly',
                                'biweekly' => 'Biweekly',
                                'monthly'  => 'Monthly',
                            ])
                            ->default('weekly')
                            ->required(),

                        TextInput::make('interval')
                            ->numeric()
                            ->default(1)
                            ->minValue(1)
                            ->required(),

                        TextInput::make('count')
                            ->label('Number of instances')
                            ->numeric()
                            ->default(12)
                            ->minValue(1)
                            ->maxValue(52)
                            ->required()
                            ->helperText('Total instances including the first one.'),
                    ]),
            ]);
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
