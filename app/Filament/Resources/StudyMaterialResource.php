<?php

namespace App\Filament\Resources;

use App\Enums\StudyMaterialType;
use App\Filament\Resources\StudyMaterialResource\Pages\CreateStudyMaterial;
use App\Filament\Resources\StudyMaterialResource\Pages\EditStudyMaterial;
use App\Filament\Resources\StudyMaterialResource\Pages\ListStudyMaterials;
use App\Models\SchoolClass;
use App\Models\StudyMaterial;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class StudyMaterialResource extends Resource
{
    protected static ?string $model = StudyMaterial::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationLabel = 'Study Materials';

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getEloquentQuery()->whereHas('classroom', fn ($q) => $q->where('teacher_id', Auth::id()));
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

                Select::make('type')
                    ->options(StudyMaterialType::class)
                    ->default(StudyMaterialType::Link->value)
                    ->live()
                    ->afterStateUpdated(function (Set $set) {
                        $set('file_path_or_url', null);
                        $set('uploaded_file', null);
                        $set('extra_metadata', null);
                        $set('meeting_title', null);
                        $set('scheduled_at', null);
                    })
                    ->required(),

                TextInput::make('title')
                    ->required()
                    ->maxLength(255),

                // FILE type: FileUpload stores to uploaded_file; merged into file_path_or_url on save
                FileUpload::make('uploaded_file')
                    ->label('File')
                    ->visible(fn (Get $get): bool => $get('type') === StudyMaterialType::File->value)
                    ->disk('public')
                    ->directory(fn (Get $get): string => 'materials/' . $get('class_id'))
                    ->acceptedFileTypes([
                        'application/pdf',
                        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        'video/mp4',
                    ])
                    ->maxSize(50 * 1024)
                    ->downloadable()
                    ->visibility('public'),

                // LINK / MEETING type: URL input mapped directly to DB column
                TextInput::make('file_path_or_url')
                    ->label('URL')
                    ->visible(fn (Get $get): bool => in_array($get('type'), [
                        StudyMaterialType::Link->value,
                        StudyMaterialType::Meeting->value,
                    ], true))
                    ->url()
                    ->maxLength(2048),

                // MEETING metadata section — sub-fields packed into extra_metadata JSON on save
                Section::make('Meeting Details')
                    ->visible(fn (Get $get): bool => $get('type') === StudyMaterialType::Meeting->value)
                    ->schema([
                        TextInput::make('meeting_title')
                            ->label('Meeting Title')
                            ->required(),
                        DateTimePicker::make('scheduled_at')
                            ->label('Scheduled At')
                            ->required(),
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
                BadgeColumn::make('type')
                    ->color(fn (StudyMaterialType $state): string => $state->getColor()),
                TextColumn::make('classroom.title')
                    ->label('Class')
                    ->searchable(),
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
                DeleteAction::make(),
            ])
            ->bulkActions([
                //
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStudyMaterials::route('/'),
            'create' => CreateStudyMaterial::route('/create'),
            'edit' => EditStudyMaterial::route('/{record}/edit'),
        ];
    }
}
