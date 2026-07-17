<?php

use App\Enums\StudyMaterialType;
use App\Models\SchoolClass;
use App\Models\StudyMaterial;
use App\Models\User;

// ---------------------------------------------------------------------------
// (a) Materials section renders after TBD block when class has materials
// ---------------------------------------------------------------------------

it('renders Materials section after TBD block when class has materials', function () {
    $teacher = User::create([
        'name' => 'Public View Teacher',
        'email' => 'pubview@example.com',
        'password' => 'password',
        'role' => 'TEACHER',
    ]);

    $class = SchoolClass::create([
        'title' => 'Public Test Class',
        'teacher_id' => $teacher->id,
        'invitation_code' => 'PUBMAT01',
    ]);

    StudyMaterial::create([
        'class_id' => $class->id,
        'title' => 'Lecture Notes',
        'type' => StudyMaterialType::File,
        'file_path_or_url' => 'materials/1/notes.pdf',
    ]);

    $response = $this->actingAs($teacher)
        ->get(route('class.join.show', 'PUBMAT01'));

    $response->assertStatus(200);
    $response->assertSee('Materials');
    $response->assertSee('Lecture Notes');
    // Materials section appears AFTER the TBD block
    $response->assertSee('TBD: join this class');
});

// ---------------------------------------------------------------------------
// (b) Materials section hidden when class has no materials
// ---------------------------------------------------------------------------

it('hides Materials section when class has no materials', function () {
    $teacher = User::create([
        'name' => 'Empty Materials Teacher',
        'email' => 'emptymat@example.com',
        'password' => 'password',
        'role' => 'TEACHER',
    ]);

    SchoolClass::create([
        'title' => 'Empty Class',
        'teacher_id' => $teacher->id,
        'invitation_code' => 'EMPTYMAT',
    ]);

    $response = $this->get(route('class.join.show', 'EMPTYMAT'));

    $response->assertStatus(200);
    $response->assertSee('Empty Class');
    // Materials section heading must NOT be present
    $response->assertDontSee('<h2>Materials</h2>', false);
});

// ---------------------------------------------------------------------------
// (c) FILE material renders as download link
// ---------------------------------------------------------------------------

it('FILE material renders as a download link', function () {
    $teacher = User::create([
        'name' => 'File View Teacher',
        'email' => 'fileview@example.com',
        'password' => 'password',
        'role' => 'TEACHER',
    ]);

    $class = SchoolClass::create([
        'title' => 'File View Class',
        'teacher_id' => $teacher->id,
        'invitation_code' => 'FILEVIEW',
    ]);

    StudyMaterial::create([
        'class_id' => $class->id,
        'title' => 'Download Me',
        'type' => StudyMaterialType::File,
        'file_path_or_url' => 'materials/1/test.pdf',
    ]);

    $response = $this->get(route('class.join.show', 'FILEVIEW'));

    $response->assertStatus(200);
    $response->assertSee('Download Me');
    $response->assertSee('download');
});

// ---------------------------------------------------------------------------
// (d) LINK with YouTube URL renders an iframe
// ---------------------------------------------------------------------------

it('LINK with YouTube URL renders embedded iframe', function () {
    $teacher = User::create([
        'name' => 'YouTube Teacher',
        'email' => 'youtube@example.com',
        'password' => 'password',
        'role' => 'TEACHER',
    ]);

    $class = SchoolClass::create([
        'title' => 'YouTube Class',
        'teacher_id' => $teacher->id,
        'invitation_code' => 'YOUTUBE1',
    ]);

    StudyMaterial::create([
        'class_id' => $class->id,
        'title' => 'Intro Video',
        'type' => StudyMaterialType::Link,
        'file_path_or_url' => 'https://www.youtube.com/watch?v=abc123def45',
    ]);

    $response = $this->get(route('class.join.show', 'YOUTUBE1'));

    $response->assertStatus(200);
    $response->assertSee('Intro Video');
    // The iframe should point to the embed URL with extracted video ID
    $response->assertSee('youtube.com/embed/abc123def45', false);
    $response->assertSee('allowfullscreen', false);
});

// ---------------------------------------------------------------------------
// (e) LINK with non-YouTube URL renders as plain anchor
// ---------------------------------------------------------------------------

it('LINK with non-YouTube URL renders as plain anchor link', function () {
    $teacher = User::create([
        'name' => 'Plain Link Teacher',
        'email' => 'plainlink@example.com',
        'password' => 'password',
        'role' => 'TEACHER',
    ]);

    $class = SchoolClass::create([
        'title' => 'Plain Link Class',
        'teacher_id' => $teacher->id,
        'invitation_code' => 'PLAINLNK',
    ]);

    StudyMaterial::create([
        'class_id' => $class->id,
        'title' => 'External Resource',
        'type' => StudyMaterialType::Link,
        'file_path_or_url' => 'https://example.com/article',
    ]);

    $response = $this->get(route('class.join.show', 'PLAINLNK'));

    $response->assertStatus(200);
    $response->assertSee('External Resource');
    $response->assertSee('https://example.com/article', false);
    // Must not render an iframe
    $response->assertDontSee('<iframe', false);
});

// ---------------------------------------------------------------------------
// (f) MEETING material renders title, formatted date, and Join meeting button
// ---------------------------------------------------------------------------

it('MEETING material renders details with Join meeting button', function () {
    $teacher = User::create([
        'name' => 'Meeting View Teacher',
        'email' => 'meetview@example.com',
        'password' => 'password',
        'role' => 'TEACHER',
    ]);

    $class = SchoolClass::create([
        'title' => 'Meeting View Class',
        'teacher_id' => $teacher->id,
        'invitation_code' => 'MEETVIEW',
    ]);

    StudyMaterial::create([
        'class_id' => $class->id,
        'title' => 'Fallback Title',
        'type' => StudyMaterialType::Meeting,
        'file_path_or_url' => 'https://meet.google.com/abc-defg-hij',
        'extra_metadata' => [
            'meeting_title' => 'Live Session',
            'scheduled_at' => '2026-07-20 14:00',
        ],
    ]);

    $response = $this->get(route('class.join.show', 'MEETVIEW'));

    $response->assertStatus(200);
    // The meeting_title (not the fallback) should display
    $response->assertSee('Live Session');
    // "Join meeting" button must be present
    $response->assertSee('Join meeting');
    // The meeting URL must appear
    $response->assertSee('meet.google.com/abc-defg-hij', false);
});
