# teacher-study-material-management Specification

## Purpose

Teacher-scoped CRUD over `study_materials` (FILE, LINK, MEETING) via Filament v5 under `/admin`. Conditional form by `type` Select with `live()`. Query scope: materials from classes owned by `Auth::id()`. Table: title, type Badge, class, `created_at`.

## Requirements

### Requirement: Study Material CRUD with Conditional Form

The system MUST allow teachers to CRUD study materials. Per `type`: FILE uses `FileUpload` (public disk, PDF/DOCX/XLSX/MP4, max 50 MB); LINK uses `TextInput` for URL; MEETING uses URL `TextInput` plus `extra_metadata` JSON (`meeting_title`, `scheduled_at`).

#### Scenario: Create FILE material

- GIVEN a teacher with a class and a valid PDF under 50 MB
- WHEN the teacher creates a FILE material
- THEN the file is stored on the `public` disk and `file_path_or_url` is set

#### Scenario: Create LINK with YouTube URL

- GIVEN a teacher with a class
- WHEN the teacher creates a LINK material with `https://www.youtube.com/watch?v=abc123def45`
- THEN `file_path_or_url` stores the URL as-is

#### Scenario: Create LINK with non-YouTube URL

- GIVEN a teacher with a class
- WHEN the teacher creates a LINK material with `https://example.com/article`
- THEN `file_path_or_url` stores the URL; no embed logic fires at the resource level

#### Scenario: Create MEETING material

- GIVEN a teacher with a class
- WHEN the teacher creates a MEETING with URL, `meeting_title` "Week 1 Live", `scheduled_at` "2026-07-20 14:00"
- THEN `extra_metadata` stores the meeting title and scheduled_at; `file_path_or_url` stores the URL

#### Scenario: Delete material

- GIVEN a teacher owns a material
- WHEN the teacher deletes it
- THEN the DB row is removed (NOTE: file cleanup on disk is deferred)

### Requirement: Conditional Form Field Reset

When `type` changes, fields from the previous type MUST clear via `afterStateUpdated`.

#### Scenario: Type change clears fields

- GIVEN a teacher has filled `file_path_or_url` and `extra_metadata`
- WHEN the teacher switches `type`
- THEN both fields reset to empty

### Requirement: Teacher Query Scope Isolation

Queries MUST scope to materials from classes owned by `Auth::id()`. `class_id` Select MUST be searchable and only list the teacher's classes.

#### Scenario: Teacher A cannot access Teacher B's materials

- GIVEN Teacher B owns material ID 42
- WHEN Teacher A views the list or attempts edit/delete for material 42
- THEN the material is not visible; direct access returns 403 or not found

#### Scenario: class_id Select scoped to teacher

- GIVEN Teacher A has 3 classes, Teacher B has 2
- WHEN Teacher A searches `class_id` on the create form
- THEN only Teacher A's 3 classes appear

### Requirement: File Upload Validation

FILE uploads MUST reject MIME types outside PDF/DOCX/XLSX/MP4 and files over 50 MB with a Filament error.

#### Scenario: Rejects disallowed MIME

- GIVEN a teacher uploads a `.exe` file
- WHEN the form submits
- THEN a Filament validation error displays; the file is not stored

#### Scenario: Rejects oversized file

- GIVEN a teacher uploads a 51 MB MP4
- WHEN the form submits
- THEN a Filament validation error displays; the file is not stored

### Requirement: Material List Display

Table MUST show materials in `created_at DESC` order: title (searchable/sortable), type Badge (FILE blue, LINK green, MEETING amber), class title, `created_at` (sortable/toggleable).

#### Scenario: Material list ordering

- GIVEN materials created July 1, July 2, July 3
- WHEN the list renders
- THEN materials appear: July 3, July 2, July 1

### Requirement: Extra Metadata JSON Round-Trip

`extra_metadata` JSON (nullable, cast to `array`) MUST round-trip for MEETING materials on edit and display.

#### Scenario: JSON round-trip

- GIVEN a MEETING saved with `meeting_title` "Week 1", `scheduled_at` "2026-07-20 14:00"
- WHEN the edit form loads
- THEN `extra_metadata.meeting_title` shows "Week 1" and `scheduled_at` shows "2026-07-20 14:00"

### Requirement: Copy Public Join URL Action

Header MUST include "Copy public join URL" action copying `https://{host}/clase/unirse/{invitation_code}`, mirroring `ClassResource`.

#### Scenario: Copy URL action

- GIVEN a class with invitation_code "abc12345"
- WHEN the teacher clicks "Copy public join URL"
- THEN `https://{host}/clase/unirse/abc12345` is copied
