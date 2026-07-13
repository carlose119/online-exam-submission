# teacher-class-management Specification

## Purpose

Teacher CRUD over classes via Filament `ClassResource` scoped to the authenticated teacher (`teacher_id = Auth::id()`). Includes auto-generated unique invitation codes, regeneration action, syllabus via `RichEditor`, copy-invitation-link action, and cross-teacher isolation enforcement.

## Requirements

### Requirement: Teacher-Scoped Class CRUD

The system MUST provide a Filament `ClassResource` under `/admin` that supports list, create, edit, and delete. The list and all actions MUST be scoped to `teacher_id = Auth::id()`.

#### Scenario: Teacher lists only their own classes

- GIVEN Teacher A has 2 classes and Teacher B has 1 class
- WHEN Teacher A accesses the class list
- THEN only Teacher A's 2 classes are displayed

#### Scenario: Teacher creates a class with auto-generated code

- GIVEN Teacher A is authenticated
- WHEN Teacher A submits the create form with title "Math 101" and description "Intro"
- THEN a class is persisted with `teacher_id = Auth::id()` and a unique `invitation_code`

#### Scenario: Teacher edits their own class

- GIVEN Teacher A's class "Math 101" exists
- WHEN Teacher A updates the title to "Advanced Math"
- THEN the class title is updated and reflected in the list

#### Scenario: Teacher deletes their own class

- GIVEN Teacher A's class "Math 101" exists
- WHEN Teacher A deletes the class
- THEN the class is removed from the database and no longer appears in the list

#### Scenario: Cross-teacher access denied

- GIVEN Teacher B's class "Physics" exists
- WHEN Teacher A directly requests the edit URL for Teacher B's class
- THEN the system returns 404

### Requirement: Invitation Code Auto-Generation

The system MUST auto-generate a unique `invitation_code` using `Str::random(8)` when a class is created. It MUST retry on collision via the database unique constraint. The code MUST NOT be user-editable via the form.

#### Scenario: Unique code generated on create

- GIVEN Teacher A creates a class
- WHEN the class is saved
- THEN `invitation_code` is an 8-character alphanumeric string unique across all classes

### Requirement: Invitation Code Regeneration

The system MUST provide a "Regenerate invitation code" Filament Action on the class edit page. When triggered, it MUST replace the existing code with a new unique 8-character string.

#### Scenario: Regenerate produces a new unique code

- GIVEN Teacher A's class has invitation_code "abc12345"
- WHEN Teacher A triggers the "Regenerate invitation code" action
- THEN `invitation_code` changes to a new unique 8-character string different from the previous value

### Requirement: Syllabus RichEditor Storage

The system MUST store the syllabus as a `longText` column via a Filament `RichEditor` form field. It MUST NOT enforce length validation. The teacher SHALL be able to edit and persist syllabus content through the class edit form.

#### Scenario: Syllabus content persists via RichEditor

- GIVEN Teacher A edits a class
- WHEN Teacher A enters formatted syllabus content via the RichEditor and saves
- THEN the content is persisted to the `syllabus` column and rendered on subsequent edits

### Requirement: Copy Invitation Link Action

The system MUST provide a "Copy invitation link" Filament Action on the class edit page. It MUST copy the full URL `https://{host}/clase/unirse/{invitation_code}` to the clipboard via JavaScript and display a persistent Filament Notification confirming the copy.

#### Scenario: Copy link action copies URL and notifies

- GIVEN Teacher A is on the edit page of a class with invitation_code "xyz98765"
- WHEN Teacher A clicks "Copy invitation link"
- THEN the URL `https://{host}/clase/unirse/xyz98765` is copied to the clipboard
- AND a persistent Notification confirms the copy was successful
