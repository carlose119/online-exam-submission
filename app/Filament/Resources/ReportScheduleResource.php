<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ReportScheduleResource\Pages\CreateReportSchedule;
use App\Filament\Resources\ReportScheduleResource\Pages\EditReportSchedule;
use App\Filament\Resources\ReportScheduleResource\Pages\ListReportSchedules;
use App\Models\ReportSchedule;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\ReportAccess;
use App\Services\ReportScheduleService;
use App\Values\ReportFilters;
use DateTimeZone;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class ReportScheduleResource extends Resource
{
    protected static ?string $model = ReportSchedule::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clock';

    protected static ?string $navigationLabel = 'Report schedules';

    private const WEEKDAYS = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();

        return parent::getEloquentQuery()
            ->where('owner_id', $user?->id ?? 0)
            ->whereHas('classroom', fn (Builder $query): Builder => app(ReportAccess::class)->scope($query, $user))
            ->with('classroom');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->schema([
            Select::make('class_id')->label('Class')->options(fn (): array => static::classOptions())->searchable()->live()
                ->afterStateUpdated(function (Set $set): void {
                    $set('filters.exam_ids', []);
                    $set('filters.student_ids', []);
                })->required(),
            Select::make('format')->options(['pdf' => 'PDF', 'xlsx' => 'XLSX'])->required(),
            Select::make('filters.exam_ids')->label('Exams')->multiple()->searchable()
                ->options(fn (Get $get): array => static::examOptions($get('class_id'))),
            Select::make('filters.student_ids')->label('Students')->multiple()->searchable()
                ->options(fn (Get $get): array => static::studentOptions($get('class_id'))),
            Select::make('filters.statuses')->label('Attempt statuses')->multiple()->options([
                'in_progress' => 'In progress', 'passed' => 'Passed', 'failed' => 'Failed',
            ]),
            DateTimePicker::make('filters.started_from')->label('Started from (inclusive)')->seconds(false),
            DateTimePicker::make('filters.started_until')->label('Started until (inclusive)')->seconds(false),
            Select::make('recurrence')->options(['daily' => 'Daily', 'weekly' => 'Weekly'])->default('daily')->live()
                ->afterStateUpdated(fn (Set $set, mixed $state) => $state === 'daily' ? $set('weekday', null) : null)->required(),
            Select::make('weekday')->options(self::WEEKDAYS)->visible(fn (Get $get): bool => $get('recurrence') === 'weekly')
                ->required(fn (Get $get): bool => $get('recurrence') === 'weekly'),
            TimePicker::make('local_time')->label('Local time')->seconds(false)->default('09:00')->required(),
            Select::make('timezone')->options(fn (): array => array_combine(DateTimeZone::listIdentifiers(), DateTimeZone::listIdentifiers()))
                ->default(config('app.timezone'))->searchable()->required(),
            Toggle::make('enabled')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('classroom.title')->label('Class'),
            TextColumn::make('format')->badge()->formatStateUsing(fn (string $state): string => strtoupper($state)),
            TextColumn::make('recurrence')->formatStateUsing(fn (string $state, ReportSchedule $record): string => $state === 'weekly' ? 'Weekly · '.self::WEEKDAYS[$record->weekday] : 'Daily'),
            TextColumn::make('local_time')->label('Local time')->formatStateUsing(fn (string $state, ReportSchedule $record): string => substr($state, 0, 5).' '.$record->timezone),
            TextColumn::make('next_run_at')->label('Next run (UTC)')->dateTime('Y-m-d H:i')->timezone('UTC')->sortable(),
            IconColumn::make('enabled')->boolean(),
        ])->recordActions([
            EditAction::make(),
            Action::make('toggle')->label(fn (ReportSchedule $record): string => $record->enabled ? 'Disable' : 'Enable')
                ->requiresConfirmation()->action(fn (ReportSchedule $record) => app(ReportScheduleService::class)
                ->setEnabled(static::actor(), (int) $record->getKey(), ! $record->enabled))->successNotificationTitle('Schedule updated'),
            Action::make('delete')->color('danger')->icon('heroicon-o-trash')->requiresConfirmation()
                ->action(fn (ReportSchedule $record) => app(ReportScheduleService::class)->delete(static::actor(), (int) $record->getKey()))
                ->successNotificationTitle('Schedule deleted'),
        ])->defaultSort('next_run_at');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReportSchedules::route('/'),
            'create' => CreateReportSchedule::route('/create'),
            'edit' => EditReportSchedule::route('/{record}/edit'),
        ];
    }

    public static function classOptions(): array
    {
        return app(ReportAccess::class)->scope(SchoolClass::query(), auth()->user())->orderBy('title')->pluck('title', 'id')->all();
    }

    public static function examOptions(mixed $classId): array
    {
        return static::optionClass($classId)?->exams()->orderBy('title')->pluck('title', 'id')->all() ?? [];
    }

    public static function studentOptions(mixed $classId): array
    {
        return static::optionClass($classId)?->students()->orderBy('name')->pluck('name', 'users.id')->all() ?? [];
    }

    public static function input(array $data): array
    {
        $classId = static::id($data['class_id'] ?? null, 'class_id');
        $class = SchoolClass::query()->findOrFail($classId);
        app(ReportAccess::class)->authorize(static::actor(), $class);
        $filters = $data['filters'] ?? null;
        if (! is_array($filters)) {
            throw ValidationException::withMessages(['filters' => 'The report filters are invalid.']);
        }
        $weekday = $data['weekday'] ?? null;
        $weekday = ($weekday === null || $weekday === '') ? null : static::id($weekday, 'weekday');
        $enabled = $data['enabled'] ?? true;
        if (! is_bool($enabled)) {
            throw ValidationException::withMessages(['enabled' => 'The enabled value must be true or false.']);
        }
        $time = static::text($data['local_time'] ?? null, 'local_time');
        $time = preg_match('/^\d{2}:\d{2}:00$/', $time) ? substr($time, 0, 5) : $time;

        return [$classId, [
            'format' => static::text($data['format'] ?? null, 'format'),
            'filters' => ReportFilters::fromTrustedForm($filters, $class)->toArray(),
            'recurrence' => static::text($data['recurrence'] ?? null, 'recurrence'),
            'weekday' => $weekday, 'local_time' => $time,
            'timezone' => static::text($data['timezone'] ?? null, 'timezone'), 'enabled' => $enabled,
        ]];
    }

    public static function actor(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    private static function optionClass(mixed $value): ?SchoolClass
    {
        try {
            $id = static::id($value, 'class_id');
        } catch (ValidationException) {
            return null;
        }
        $user = auth()->user();

        return $user instanceof User ? app(ReportAccess::class)->scope(SchoolClass::query(), $user)->find($id) : null;
    }

    private static function id(mixed $value, string $key): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^[1-9]\d*$/', $value) && filter_var($value, FILTER_VALIDATE_INT) !== false) {
            return (int) $value;
        }

        throw ValidationException::withMessages([$key => 'The selected value is invalid.']);
    }

    private static function text(mixed $value, string $key): string
    {
        if (! is_string($value)) {
            throw ValidationException::withMessages([$key => "The {$key} value is invalid."]);
        }

        return $value;
    }
}
