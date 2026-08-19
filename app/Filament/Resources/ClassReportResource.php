<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ClassReportResource\Pages\ClassReport;
use App\Filament\Resources\ClassReportResource\Pages\ListClassReports;
use App\Models\SchoolClass;
use App\Services\ReportAccess;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ClassReportResource extends Resource
{
    protected static ?string $model = SchoolClass::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationLabel = 'Reports';

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            ->withCount(['exams', 'students'])
            ->withCount(['exams as attempts_count' => function (Builder $q): void {
                $q->join('student_attempts', 'student_attempts.exam_id', '=', 'exams.id');
            }]);

        return app(ReportAccess::class)->scope($query, auth()->user());
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('teacher.name')
                    ->label('Teacher')
                    ->sortable(),
                TextColumn::make('exams_count')
                    ->label('# Exams')
                    ->sortable()
                    ->alignEnd(),
                TextColumn::make('students_count')
                    ->label('# Students')
                    ->sortable()
                    ->alignEnd(),
                TextColumn::make('attempts_count')
                    ->label('# Attempts')
                    ->sortable()
                    ->alignEnd(),
            ])
            ->actions([
                Action::make('viewReport')
                    ->label('View Report')
                    ->icon('heroicon-o-eye')
                    ->url(fn (SchoolClass $record): string => static::getUrl('report', ['record' => $record])),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListClassReports::route('/'),
            'report' => ClassReport::route('/{record}/report'),
        ];
    }
}
