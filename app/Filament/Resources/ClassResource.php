<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ClassResource\Pages\CreateClass;
use App\Filament\Resources\ClassResource\Pages\EditClass;
use App\Filament\Resources\ClassResource\Pages\ListClasses;
use App\Models\SchoolClass;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Illuminate\Support\Facades\Auth;

class ClassResource extends Resource
{
    protected static ?string $model = SchoolClass::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-academic-cap';

    protected static ?string $navigationLabel = 'Classes';

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getEloquentQuery()->where('teacher_id', Auth::id());
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                TextInput::make('title')
                    ->required()
                    ->maxLength(255),
                Textarea::make('description')
                    ->label('Description')
                    ->nullable()
                    ->columnSpanFull(),
                RichEditor::make('syllabus')
                    ->label('Syllabus')
                    ->nullable()
                    ->columnSpanFull(),
                Hidden::make('teacher_id')
                    ->default(fn () => Auth::id()),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('description')
                    ->limit(50)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('teacher.name')
                    ->label('Teacher')
                    ->sortable(),
                TextColumn::make('invitation_code')
                    ->label('Invitation Code')
                    ->badge()
                    ->copyable()
                    ->searchable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
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
            'index' => ListClasses::route('/'),
            'create' => CreateClass::route('/create'),
            'edit' => EditClass::route('/{record}/edit'),
        ];
    }
}
