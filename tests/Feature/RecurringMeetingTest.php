<?php

use App\Models\Meeting;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

// ---------------------------------------------------------------------------
// 1. Migration columns exist
// ---------------------------------------------------------------------------

it('adds the recurrence_rule and parent_id columns to the meetings table', function () {
    expect(Schema::hasColumn('meetings', 'recurrence_rule'))->toBeTrue();
    expect(Schema::hasColumn('meetings', 'parent_id'))->toBeTrue();
});

// ---------------------------------------------------------------------------
// 2. Model relations and methods
// ---------------------------------------------------------------------------

it('has the parent, children, isRecurring, recurrenceRule, and generateInstances methods', function () {
    $teacher = User::create([
        'name' => 'RecurModel Teacher',
        'email' => 'recurmodel@test.com',
        'password' => 'password',
        'role' => 'TEACHER',
    ]);

    $class = SchoolClass::create([
        'title' => 'RecurModel Class',
        'teacher_id' => $teacher->id,
        'invitation_code' => 'RECMOD01',
    ]);

    // Parent with recurrence rule
    $parent = Meeting::create([
        'class_id' => $class->id,
        'title' => 'Weekly Standup',
        'scheduled_at' => now()->addHour(),
        'recurrence_rule' => json_encode([
            'frequency' => 'weekly',
            'interval' => 1,
            'count' => 4,
        ]),
    ]);

    // Child with parent_id
    $child = Meeting::create([
        'class_id' => $class->id,
        'title' => 'Weekly Standup',
        'scheduled_at' => now()->addWeek(),
        'parent_id' => $parent->id,
    ]);

    // --- parent() relation ---
    expect($child->parent)->not->toBeNull();
    expect($child->parent->id)->toBe($parent->id);

    // --- children() relation ---
    expect($parent->children)->toHaveCount(1);
    expect($parent->children->first()->id)->toBe($child->id);

    // --- isRecurring() ---
    expect($parent->fresh()->isRecurring())->toBeTrue();
    expect($child->fresh()->isRecurring())->toBeFalse();

    // --- recurrenceRule() accessor ---
    $rule = $parent->fresh()->recurrenceRule();
    expect($rule)->not->toBeNull();
    expect($rule['frequency'])->toBe('weekly');
    expect($rule['interval'])->toBe(1);

    // --- generateInstances() ---
    $parent2 = Meeting::create([
        'class_id' => $class->id,
        'title' => 'Biweekly Sync',
        'scheduled_at' => now()->addHour(),
        'recurrence_rule' => json_encode([
            'frequency' => 'biweekly',
            'interval' => 1,
        ]),
    ]);

    $children = $parent2->generateInstances(4);
    expect($children)->toHaveCount(3);
    expect(Meeting::where('parent_id', $parent2->id)->count())->toBe(3);

    // Verify child properties
    foreach ($children as $c) {
        expect($c->class_id)->toBe($parent2->class_id);
        expect($c->title)->toBe($parent2->title);
        expect($c->recurrence_rule)->toBeNull();
        expect($c->parent_id)->toBe($parent2->id);
    }

    // Verify scheduling: weekly should be +7*days, biweekly should be +14*days
    $weekly = Meeting::create([
        'class_id' => $class->id,
        'title' => 'Weekly',
        'scheduled_at' => now()->parse('2026-08-01 10:00:00'),
        'recurrence_rule' => json_encode(['frequency' => 'weekly', 'interval' => 1]),
    ]);
    $weeklyChildren = $weekly->generateInstances(3);
    expect($weeklyChildren)->toHaveCount(2);
    expect($weeklyChildren[0]->scheduled_at->format('Y-m-d'))->toBe('2026-08-08');
    expect($weeklyChildren[1]->scheduled_at->format('Y-m-d'))->toBe('2026-08-15');

    // Biweekly should be +14 days per interval
    $biweekly = Meeting::create([
        'class_id' => $class->id,
        'title' => 'Biweekly',
        'scheduled_at' => now()->parse('2026-08-01 10:00:00'),
        'recurrence_rule' => json_encode(['frequency' => 'biweekly', 'interval' => 1]),
    ]);
    $biweeklyChildren = $biweekly->generateInstances(3);
    expect($biweeklyChildren)->toHaveCount(2);
    expect($biweeklyChildren[0]->scheduled_at->format('Y-m-d'))->toBe('2026-08-15');
    expect($biweeklyChildren[1]->scheduled_at->format('Y-m-d'))->toBe('2026-08-29');

    // Monthly should use addMonthsNoOverflow
    $monthly = Meeting::create([
        'class_id' => $class->id,
        'title' => 'Monthly',
        'scheduled_at' => now()->parse('2026-01-31 10:00:00'),
        'recurrence_rule' => json_encode(['frequency' => 'monthly', 'interval' => 1]),
    ]);
    $monthlyChildren = $monthly->generateInstances(3);
    expect($monthlyChildren)->toHaveCount(2);
    expect($monthlyChildren[0]->scheduled_at->format('Y-m-d'))->toBe('2026-02-28');
    expect($monthlyChildren[1]->scheduled_at->format('Y-m-d'))->toBe('2026-03-31');
});

// ---------------------------------------------------------------------------
// 3. One-off meeting creation (no recurrence)
// ---------------------------------------------------------------------------

it('creates a one-off meeting without recurrence data', function () {
    $teacher = User::create([
        'name' => 'OneOff Teacher',
        'email' => 'oneoff@test.com',
        'password' => 'password',
        'role' => 'TEACHER',
    ]);

    $class = SchoolClass::create([
        'title' => 'OneOff Class',
        'teacher_id' => $teacher->id,
        'invitation_code' => 'ONEOFF01',
    ]);

    $meeting = Meeting::create([
        'class_id' => $class->id,
        'title' => 'One-off Meeting',
        'scheduled_at' => now()->addHour(),
    ]);

    expect($meeting->recurrence_rule)->toBeNull();
    expect($meeting->parent_id)->toBeNull();
    expect($meeting->isRecurring())->toBeFalse();
    expect(Meeting::where('parent_id', $meeting->id)->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// 4. Recurring series creation
// ---------------------------------------------------------------------------

it('creates a recurring series of N instances via generateInstances', function () {
    $teacher = User::create([
        'name' => 'Series Teacher',
        'email' => 'series@test.com',
        'password' => 'password',
        'role' => 'TEACHER',
    ]);

    $class = SchoolClass::create([
        'title' => 'Series Class',
        'teacher_id' => $teacher->id,
        'invitation_code' => 'SERIES01',
    ]);

    $parent = Meeting::create([
        'class_id' => $class->id,
        'title' => 'Weekly Tutoring',
        'scheduled_at' => now()->parse('2026-08-03 15:00:00'),
        'duration_minutes' => 90,
        'meeting_url' => 'https://meet.google.com/abc-defg-hij',
        'agenda' => 'Chapter review',
        'recurrence_rule' => json_encode([
            'frequency' => 'weekly',
            'interval' => 1,
        ]),
    ]);

    expect(Meeting::count())->toBe(1);

    $children = $parent->generateInstances(5);

    expect($children)->toHaveCount(4);
    expect(Meeting::count())->toBe(5);

    foreach ($children as $child) {
        expect($child->parent_id)->toBe($parent->id);
        expect($child->class_id)->toBe($parent->class_id);
        expect($child->title)->toBe($parent->title);
        expect($child->duration_minutes)->toBe(90);
        expect($child->meeting_url)->toBe('https://meet.google.com/abc-defg-hij');
        expect($child->agenda)->toBe('Chapter review');
        expect($child->recurrence_rule)->toBeNull(); // children don't carry the rule
    }
});

// ---------------------------------------------------------------------------
// 5. Edit-all propagation (future updated, past untouched)
// ---------------------------------------------------------------------------

it('propagates edit changes to future children but not past', function () {
    Carbon::setTestNow('2026-08-03 12:00:00');

    $teacher = User::create([
        'name' => 'EditProp Teacher',
        'email' => 'editprop@test.com',
        'password' => 'password',
        'role' => 'TEACHER',
    ]);

    $class = SchoolClass::create([
        'title' => 'EditProp Class',
        'teacher_id' => $teacher->id,
        'invitation_code' => 'EDTPRP01',
    ]);

    $parent = Meeting::create([
        'class_id' => $class->id,
        'title' => 'Original Title',
        'scheduled_at' => now()->parse('2026-08-01 10:00:00'),
        'duration_minutes' => 60,
        'recurrence_rule' => json_encode(['frequency' => 'weekly', 'interval' => 1]),
    ]);

    // Past child (yesterday)
    $pastChild = Meeting::create([
        'class_id' => $class->id,
        'title' => 'Original Title',
        'scheduled_at' => now()->subDay(),
        'parent_id' => $parent->id,
    ]);

    // Future children (tomorrow and day after)
    $futureChild1 = Meeting::create([
        'class_id' => $class->id,
        'title' => 'Original Title',
        'scheduled_at' => now()->addDay(),
        'parent_id' => $parent->id,
    ]);

    $futureChild2 = Meeting::create([
        'class_id' => $class->id,
        'title' => 'Original Title',
        'scheduled_at' => now()->addDays(2),
        'parent_id' => $parent->id,
    ]);

    // Unrelated meeting (should not be affected)
    $unrelated = Meeting::create([
        'class_id' => $class->id,
        'title' => 'Unrelated',
        'scheduled_at' => now()->addDay(),
    ]);

    // Simulate the EditMeeting afterSave hook
    $parent->update(['title' => 'Updated Title']);

    $parent->children()
        ->where('scheduled_at', '>=', now())
        ->update([
            'title' => $parent->title,
            'agenda' => $parent->agenda,
            'duration_minutes' => $parent->duration_minutes,
            'meeting_url' => $parent->meeting_url,
        ]);

    // Assertions
    expect($pastChild->fresh()->title)->toBe('Original Title');
    expect($futureChild1->fresh()->title)->toBe('Updated Title');
    expect($futureChild2->fresh()->title)->toBe('Updated Title');
    expect($unrelated->fresh()->title)->toBe('Unrelated');

    Carbon::setTestNow();
});

// ---------------------------------------------------------------------------
// 6. Delete cascade
// ---------------------------------------------------------------------------

it('deletes all children when parent is deleted via cascade', function () {
    $teacher = User::create([
        'name' => 'CascadeRec Teacher',
        'email' => 'cascaderec@test.com',
        'password' => 'password',
        'role' => 'TEACHER',
    ]);

    $class = SchoolClass::create([
        'title' => 'CascadeRec Class',
        'teacher_id' => $teacher->id,
        'invitation_code' => 'CASREC01',
    ]);

    $parent = Meeting::create([
        'class_id' => $class->id,
        'title' => 'Parent',
        'scheduled_at' => now()->addHour(),
    ]);

    $child1 = Meeting::create([
        'class_id' => $class->id,
        'title' => 'Child 1',
        'scheduled_at' => now()->addWeek(),
        'parent_id' => $parent->id,
    ]);

    $child2 = Meeting::create([
        'class_id' => $class->id,
        'title' => 'Child 2',
        'scheduled_at' => now()->addWeeks(2),
        'parent_id' => $parent->id,
    ]);

    expect(Meeting::count())->toBe(3);

    $parent->delete();

    expect(Meeting::find($parent->id))->toBeNull();
    expect(Meeting::find($child1->id))->toBeNull();
    expect(Meeting::find($child2->id))->toBeNull();
    expect(Meeting::count())->toBe(0);
});

// ---------------------------------------------------------------------------
// 7. recurrence_rule JSON round-trip
// ---------------------------------------------------------------------------

it('correctly stores and retrieves the recurrence_rule JSON structure', function () {
    $teacher = User::create([
        'name' => 'JsonRound Teacher',
        'email' => 'jsonround@test.com',
        'password' => 'password',
        'role' => 'TEACHER',
    ]);

    $class = SchoolClass::create([
        'title' => 'JsonRound Class',
        'teacher_id' => $teacher->id,
        'invitation_code' => 'JSONRND01',
    ]);

    $rule = [
        'frequency' => 'biweekly',
        'interval' => 2,
        'count' => 8,
        'until' => null,
        'days_of_week' => null,
    ];

    $meeting = Meeting::create([
        'class_id' => $class->id,
        'title' => 'JSON Round-trip',
        'scheduled_at' => now()->addHour(),
        'recurrence_rule' => json_encode($rule),
    ]);

    $retrieved = $meeting->fresh()->recurrenceRule();

    expect($retrieved)->toBeArray();
    expect($retrieved['frequency'])->toBe('biweekly');
    expect($retrieved['interval'])->toBe(2);
    expect($retrieved['count'])->toBe(8);
    expect($retrieved['until'])->toBeNull();
    expect($retrieved['days_of_week'])->toBeNull();
});
