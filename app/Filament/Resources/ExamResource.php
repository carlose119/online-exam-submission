<?php

namespace App\Filament\Resources;

use App\Enums\QuestionType;
use App\Filament\Resources\ExamResource\Pages\CreateExam;
use App\Filament\Resources\ExamResource\Pages\EditExam;
use App\Filament\Resources\ExamResource\Pages\ListExams;
use App\Models\Exam;
use App\Models\SchoolClass;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class ExamResource extends Resource
{
    protected static ?string $model = Exam::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationLabel = 'Exams';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereHas('classroom', fn ($q) => $q->where('teacher_id', Auth::id()))
            ->withCount('questions');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Select::make('class_id')
                    ->label('Class')
                    ->options(fn () => SchoolClass::where('teacher_id', Auth::id())->pluck('title', 'id')->toArray())
                    ->searchable()
                    ->required(),

                Section::make('Exam Details')
                    ->schema([
                        TextInput::make('title')
                            ->required()
                            ->maxLength(255),
                        Textarea::make('description')
                            ->nullable()
                            ->columnSpanFull(),
                        TextInput::make('duration_minutes')
                            ->numeric()
                            ->suffix('minutes')
                            ->minValue(1)
                            ->maxValue(600)
                            ->required()
                            ->default(60),
                        TextInput::make('max_score')
                            ->numeric()
                            ->minValue(1)
                            ->required()
                            ->default(100)
                            ->helperText('Defaults to sum of question points.'),
                    ]),

                Repeater::make('questions')
                    ->relationship('questions')
                    ->orderColumn('order')
                    ->schema([
                        Textarea::make('text')
                            ->required(),
                        Select::make('type')
                            ->options(QuestionType::class)
                            ->default(QuestionType::Single->value)
                            ->live()
                            ->afterStateUpdated(function ($state) {
                                Notification::make()
                                    ->title('Review correct options')
                                    ->warning()
                                    ->body('Changing the question type may require updating the is_correct flags on each option.')
                                    ->send();
                            })
                            ->required(),
                        TextInput::make('points')
                            ->numeric()
                            ->minValue(1)
                            ->required()
                            ->default(1),

                        Repeater::make('options')
                            ->relationship('options')
                            ->schema([
                                TextInput::make('text')
                                    ->required(),
                                Toggle::make('is_correct')
                                    ->default(false),
                            ])
                            ->minItems(2),
                    ])
                    ->minItems(1),
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
                BadgeColumn::make('duration_minutes')
                    ->label('Duration')
                    ->formatStateUsing(fn (int $state): string => "{$state} min"),
                BadgeColumn::make('max_score')
                    ->label('Max Score'),
                BadgeColumn::make('questions_count')
                    ->label('Questions')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                //
            ])
            ->actions([
                EditAction::make(),
                DeleteAction::make()
                    ->modalDescription('This exam, its questions, and all answer options will be permanently deleted.'),
            ])
            ->bulkActions([
                //
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListExams::route('/'),
            'create' => CreateExam::route('/create'),
            'edit' => EditExam::route('/{record}/edit'),
        ];
    }
}
