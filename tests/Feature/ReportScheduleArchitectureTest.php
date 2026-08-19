<?php

use PhpParser\Node;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

function reportScheduleWriteViolations(): array
{
    $reads = ['query', 'newQuery', 'table', 'select', 'addSelect', 'where', 'whereKey', 'whereIn', 'whereNotIn', 'whereNull', 'whereNotNull', 'whereHas', 'with', 'withCount', 'orderBy', 'orderByDesc', 'latest', 'oldest', 'groupBy', 'having', 'distinct', 'limit', 'take', 'offset', 'skip', 'get', 'find', 'findOrFail', 'first', 'firstOrFail', 'paginate', 'simplePaginate', 'cursorPaginate', 'value', 'pluck', 'count', 'exists', 'doesntExist', 'min', 'max', 'avg', 'sum', 'fresh', 'refresh', 'load', 'loadMissing', 'relationLoaded', 'getKey', 'getAttribute', 'toArray', 'owner', 'classroom', 'belongsTo', 'reportSchedules', 'getRecord'];
    $parser = (new ParserFactory)->createForNewestSupportedVersion();
    $finder = new NodeFinder;
    $violations = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen(app_path()) + 1));
        if ($file->getExtension() !== 'php' || $relative === 'Services/ReportScheduleService.php') {
            continue;
        }
        $source = file_get_contents($file->getPathname());
        if (! str_contains($source, 'ReportSchedule') && ! str_contains($source, 'report_schedules') && ! str_contains($relative, 'ReportScheduleResource')) {
            continue;
        }
        $nodes = $parser->parse($source) ?? [];
        preg_match('/use\s+App\\\\Models\\\\ReportSchedule(?:\s+as\s+(\w+))?/', $source, $import);
        $models = ['ReportSchedule', $import[1] ?? 'ReportSchedule'];
        $resource = str_contains($relative, 'ReportScheduleResource');
        $references = ($resource ? ['record' => true, 'query' => true] : []) + ($relative === 'Models/ReportSchedule.php' ? ['this' => true] : []);
        $name = fn (Node $node): ?string => $node->name instanceof Identifier ? $node->name->toString() : null;
        $concern = function ($expr) use (&$concern, &$references, $models, $name, $resource): bool {
            if (! $expr instanceof Node) {
                return false;
            }

            return match ($expr->getType()) {
                'Expr_Variable' => is_string($expr->name) && isset($references[$expr->name]),
                'Expr_New' => method_exists($expr->class, 'getLast') && in_array($expr->class->getLast(), $models, true),
                'Expr_StaticCall' => (method_exists($expr->class, 'getLast') && in_array($expr->class->getLast(), $models, true)) || ($name($expr) === 'table' && ($expr->args[0]->value->value ?? null) === 'report_schedules'),
                'Expr_MethodCall', 'Expr_NullsafeMethodCall' => $concern($expr->var) || $name($expr) === 'reportSchedules' || ($resource && $name($expr) === 'getRecord'),
                'Expr_PropertyFetch', 'Expr_NullsafePropertyFetch' => ($concern($expr->var) && in_array($name($expr), ['owner', 'classroom', 'reportSchedules'], true)) || ($resource && $name($expr) === 'record'),
                default => false,
            };
        };
        $target = fn (Node $call): bool => $call instanceof StaticCall ? (method_exists($call->class, 'getLast') && in_array($call->class->getLast(), $models, true)) || str_contains((string) ($call->args[0]->value->value ?? ''), 'report_schedules') : $concern($call->var);
        foreach ($finder->find($nodes, fn (Node $node): bool => $node->getType() === 'Param') as $parameter) {
            if (is_object($parameter->type) && method_exists($parameter->type, 'getLast') && in_array($parameter->type->getLast(), $models, true)) {
                $references[$parameter->var->name] = true;
            }
        }
        do {
            $count = count($references);
            foreach ($finder->find($nodes, fn (Node $node): bool => $node->getType() === 'Expr_Assign') as $assignment) {
                if ($assignment->var->getType() === 'Expr_Variable' && is_string($assignment->var->name) && $concern($assignment->expr)) {
                    $references[$assignment->var->name] = true;
                }
            }
            foreach ($finder->find($nodes, fn (Node $node): bool => in_array($node->getType(), ['Expr_MethodCall', 'Expr_NullsafeMethodCall', 'Expr_StaticCall'], true) && $name($node) === 'where' && $target($node)) as $call) {
                $parameter = $call->args[0]->value->params[0]->var->name ?? null;
                $references += is_string($parameter) ? [$parameter => true] : [];
            }
        } while (count($references) > $count);
        foreach ($finder->find($nodes, fn (Node $node): bool => in_array($node->getType(), ['Expr_MethodCall', 'Expr_NullsafeMethodCall', 'Expr_StaticCall'], true)) as $call) {
            if ($target($call) && ($name($call) === null || ! in_array($name($call), $reads, true))) {
                $violations[] = $relative;
                break;
            }
        }
    }

    return $violations;
}

it('keeps report schedule application writes inside the service', function () {
    expect(reportScheduleWriteViolations())->toBe([]);
    $challenges = [
        'ReportScheduleWhereClosureViolation' => ['use App\\Models\\ReportSchedule; ReportSchedule::query()->where(function ($query) { $query->delete(); });', true],
        'ReportScheduleDatabaseAliasViolation' => ["use Illuminate\\Support\\Facades\\DB as Database; Database::table('report_schedules')->delete();", true],
        'ReportScheduleModelAliasViolation' => ['use App\\Models\\ReportSchedule as Schedule; Schedule::query()->delete();', true],
        'ReportScheduleSplitBuilderViolation' => ['use App\\Models\\ReportSchedule; $query = ReportSchedule::query(); $query->update([]);', true],
        'ReportScheduleResourceRecordViolation' => ['class ReportScheduleResourceRecordViolation { function run($record) { $record->update([]); } }', true],
        'ReportScheduleSaveOrFailViolation' => ['use App\\Models\\ReportSchedule; $schedule = new ReportSchedule; $schedule->saveOrFail();', true],
        'ReportScheduleUpdateQuietlyViolation' => ['use App\\Models\\ReportSchedule; ReportSchedule::query()->first()->updateQuietly([]);', true],
        'ReportSchedulePushViolation' => ['use App\\Models\\ReportSchedule; ReportSchedule::find(1)->push();', true],
        'ReportSchedulePushQuietlyViolation' => ['use App\\Models\\ReportSchedule; ReportSchedule::find(1)->pushQuietly();', true],
        'ReportScheduleForceCreateQuietlyViolation' => ['use App\\Models\\ReportSchedule; ReportSchedule::forceCreateQuietly([]);', true],
        'ReportScheduleTruncateViolation' => ['use App\\Models\\ReportSchedule; ReportSchedule::query()->truncate();', true],
        'ReportScheduleFutureViolation' => ['use App\\Models\\ReportSchedule; ReportSchedule::query()->persistInFuture();', true],
        'ReportScheduleReadOnly' => ["use App\\Models\\ReportSchedule as Schedule; \$query = Schedule::query()->select('id')->where('enabled', true)->orderBy('id'); \$page = \$query->paginate(); \$schedule = Schedule::find(1); \$owner = \$schedule->owner; \$relation = \$schedule->owner()->first();", false],
    ];
    foreach ($challenges as $name => [$source, $violation]) {
        $file = app_path($name.'.php');
        file_put_contents($file, "<?php {$source}\n");
        try {
            expect(in_array($name.'.php', reportScheduleWriteViolations(), true))->toBe($violation);
        } finally {
            @unlink($file);
        }
    }
});
