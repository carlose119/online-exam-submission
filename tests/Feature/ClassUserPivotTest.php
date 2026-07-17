<?php

use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Database\QueryException;

// ---------------------------------------------------------------------------
// Pivot table has expected columns
// ---------------------------------------------------------------------------

it('class_user table has expected columns', function () {
    $teacher = User::create([
        'name' => 'Schema Teacher',
        'email' => 'schema-teacher@test.com',
        'password' => 'password',
        'role' => 'TEACHER',
    ]);

    $class = SchoolClass::create([
        'title' => 'Schema Class',
        'teacher_id' => $teacher->id,
        'invitation_code' => 'SCHEMA99',
    ]);

    $student = User::create([
        'name' => 'Schema Student',
        'email' => 'schema-student@test.com',
        'password' => 'password',
        'role' => 'STUDENT',
    ]);

    $student->subscribedClasses()->attach($class->id);

    $this->assertDatabaseHas('class_user', [
        'class_id' => $class->id,
        'user_id' => $student->id,
    ]);

    // Verify timestamps are populated
    $row = \App\Models\ClassUser::first();
    expect($row->created_at)->not->toBeNull();
    expect($row->updated_at)->not->toBeNull();
});

// ---------------------------------------------------------------------------
// DB UNIQUE constraint blocks duplicate (class_id, user_id)
// ---------------------------------------------------------------------------

it('unique constraint blocks duplicate class_user rows', function () {
    $teacher = User::create([
        'name' => 'Unique Teacher',
        'email' => 'unique-teacher@test.com',
        'password' => 'password',
        'role' => 'TEACHER',
    ]);

    $class = SchoolClass::create([
        'title' => 'Unique Class',
        'teacher_id' => $teacher->id,
        'invitation_code' => 'UNIQUE99',
    ]);

    $student = User::create([
        'name' => 'Unique Student',
        'email' => 'unique-student@test.com',
        'password' => 'password',
        'role' => 'STUDENT',
    ]);

    // First insert via Eloquent
    $student->subscribedClasses()->attach($class->id);

    // Second insert bypasses Eloquent — should throw
    $this->expectException(QueryException::class);

    \Illuminate\Support\Facades\DB::table('class_user')->insert([
        'class_id' => $class->id,
        'user_id' => $student->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

// ---------------------------------------------------------------------------
// Cascade delete: deleting a class removes its subscriptions
// ---------------------------------------------------------------------------

it('cascade deletes subscriptions when class is deleted', function () {
    $teacher = User::create([
        'name' => 'Cascade Teacher',
        'email' => 'cascade-teacher@test.com',
        'password' => 'password',
        'role' => 'TEACHER',
    ]);

    $class = SchoolClass::create([
        'title' => 'Cascade Class',
        'teacher_id' => $teacher->id,
        'invitation_code' => 'CASCDEL1',
    ]);

    $student = User::create([
        'name' => 'Cascade Student',
        'email' => 'cascade-student@test.com',
        'password' => 'password',
        'role' => 'STUDENT',
    ]);

    $student->subscribedClasses()->attach($class->id);

    $this->assertDatabaseCount('class_user', 1);

    $class->delete();

    $this->assertDatabaseCount('class_user', 0);
});

// ---------------------------------------------------------------------------
// Cascade delete: deleting a user removes their subscriptions
// ---------------------------------------------------------------------------

it('cascade deletes subscriptions when user is deleted', function () {
    $teacher = User::create([
        'name' => 'Cascade2 Teacher',
        'email' => 'cascade2-teacher@test.com',
        'password' => 'password',
        'role' => 'TEACHER',
    ]);

    $class = SchoolClass::create([
        'title' => 'Cascade2 Class',
        'teacher_id' => $teacher->id,
        'invitation_code' => 'CASC2DEL',
    ]);

    $student = User::create([
        'name' => 'Cascade2 Student',
        'email' => 'cascade2-student@test.com',
        'password' => 'password',
        'role' => 'STUDENT',
    ]);

    $student->subscribedClasses()->attach($class->id);

    $this->assertDatabaseCount('class_user', 1);

    $student->delete();

    $this->assertDatabaseCount('class_user', 0);
});

// ---------------------------------------------------------------------------
// Relationships resolve with timestamps populated on pivot rows
// ---------------------------------------------------------------------------

it('relationships resolve correctly with timestamps', function () {
    $teacher = User::create([
        'name' => 'Rel Teacher',
        'email' => 'rel-teacher@test.com',
        'password' => 'password',
        'role' => 'TEACHER',
    ]);

    $class = SchoolClass::create([
        'title' => 'Rel Class',
        'teacher_id' => $teacher->id,
        'invitation_code' => 'REL12345',
    ]);

    $student = User::create([
        'name' => 'Rel Student',
        'email' => 'rel-student@test.com',
        'password' => 'password',
        'role' => 'STUDENT',
    ]);

    $student->subscribedClasses()->attach($class->id);

    // students() relationship on SchoolClass
    expect($class->fresh()->students)->toHaveCount(1);
    expect($class->fresh()->students->first()->id)->toBe($student->id);

    // subscribedClasses() relationship on User
    expect($student->fresh()->subscribedClasses)->toHaveCount(1);
    expect($student->fresh()->subscribedClasses->first()->id)->toBe($class->id);

    // Timestamps on pivot
    $pivot = $class->fresh()->students->first()->pivot;
    expect($pivot->created_at)->not->toBeNull();
    expect($pivot->updated_at)->not->toBeNull();
});
