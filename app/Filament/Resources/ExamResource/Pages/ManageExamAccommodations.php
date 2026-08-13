<?php

namespace App\Filament\Resources\ExamResource\Pages;

use App\Filament\Resources\ExamResource;
use App\Models\ExamAllowance;
use App\Models\User;
use App\Services\ExamAllowanceService;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class ManageExamAccommodations extends ManageRelatedRecords
{
    protected static string $resource = ExamResource::class;

    protected static string $relationship = 'students';

    protected static ?string $title = 'Exam accommodations';

    protected static ?string $navigationLabel = 'Accommodations';

    public static function canAccess(array $parameters = []): bool
    {
        $record = $parameters['record'] ?? null;

        return $record instanceof Model
            && auth()->user()?->role === 'TEACHER'
            && $record->classroom()->where('teacher_id', auth()->id())->exists();
    }

    public function table(Table $table): Table
    {
        $exam = $this->getRecord();

        return $table
            ->modifyQueryUsing(fn ($query) => $query
                ->with(['examAllowances' => fn ($query) => $query->where('exam_id', $exam->id)])
                ->withCount(['studentAttempts as consumed_attempts' => fn ($query) => $query->where('exam_id', $exam->id)]))
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')->label('Student')->searchable(),
                TextColumn::make('base_attempts')->label('Base')->state(1),
                TextColumn::make('additional_attempts')->label('Additional')->state(fn (User $record): int => $this->allowance($record)->additional_attempts ?? 0),
                TextColumn::make('consumed_attempts')->label('Consumed'),
                TextColumn::make('remaining_attempts')->label('Remaining')->state(fn (User $record): int => max(0, 1 + ($this->allowance($record)->additional_attempts ?? 0) - $record->consumed_attempts)),
                TextColumn::make('effective_attempts')->label('Effective')->state(fn (User $record): int => 1 + ($this->allowance($record)->additional_attempts ?? 0)),
                TextColumn::make('base_time')->label('Base time')->state("{$exam->duration_minutes} min"),
                TextColumn::make('extra_time')->label('Additional time')->state(fn (User $record): string => ($this->allowance($record)->extra_time_minutes ?? 0).' min'),
                TextColumn::make('effective_time')->label('Effective time')->state(fn (User $record): string => ($exam->duration_minutes + ($this->allowance($record)->extra_time_minutes ?? 0)).' min'),
            ])
            ->recordActions([
                Action::make('manage')
                    ->icon('heroicon-o-adjustments-horizontal')
                    ->fillForm(fn (User $record): array => [
                        'additional_attempts' => $this->allowance($record)->additional_attempts ?? 0,
                        'extra_time_minutes' => $this->allowance($record)->extra_time_minutes ?? 0,
                    ])
                    ->form([
                        TextInput::make('additional_attempts')
                            ->numeric()
                            ->integer()
                            ->minValue(0)
                            ->maxValue(ExamAllowanceService::MAX_ADDITIONAL_ATTEMPTS)
                            ->required()
                            ->helperText('May not be lower than the number of additional attempts already consumed.'),
                        TextInput::make('extra_time_minutes')
                            ->label('Additional time')
                            ->numeric()
                            ->integer()
                            ->minValue(0)
                            ->maxValue(ExamAllowanceService::MAX_EXTRA_TIME_MINUTES)
                            ->suffix('minutes')
                            ->required()
                            ->helperText('Applies only when the student starts a future attempt.'),
                    ])
                    ->action(function (User $record, array $data): void {
                        app(ExamAllowanceService::class)->saveForTeacher(
                            $this->getRecord(),
                            $record,
                            auth()->user(),
                            (int) $data['additional_attempts'],
                            (int) $data['extra_time_minutes'],
                        );
                    })
                    ->successNotificationTitle('Exam accommodation saved'),
            ]);
    }

    private function allowance(User $student): ?ExamAllowance
    {
        return $student->examAllowances->first();
    }
}
