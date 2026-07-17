# class-invitation-flow Specification

## Purpose

Public (unauthenticated) route `clase/unirse/{invitation_code}` rendering class details via a Blade view outside the Filament panel. Auth-aware join affordance: authenticated users see a TBD placeholder (no actual subscription is created); unauthenticated users see a login link. The invitation link URL format is `https://{host}/clase/unirse/{invitation_code}`. Additionally, the join page renders a "Materials" section displaying the class's study materials (files, links, meetings) after the TBD block.

## Requirements

### Requirement: Public Invitation Route

The system MUST expose a public GET route `clase/unirse/{invitation_code}` outside the Filament panel. It MUST render a Blade view showing the class title, description, and syllabus for any visitor regardless of authentication state.

#### Scenario: Public route renders class details

- GIVEN a class with invitation_code "abc12345", title "Math 101", description "Intro course", and syllabus "Week 1: Algebra"
- WHEN an unauthenticated user visits `/clase/unirse/abc12345`
- THEN HTTP 200 is returned
- AND the view displays "Math 101", the description, and the syllabus content

### Requirement: Auth-Aware Join Affordance

The system MUST render different join affordances based on authentication state. Unauthenticated users MUST see a "Log in to join" link pointing to `/admin/login`. Authenticated users MUST see a button labeled "TBD: join this class" that does NOT create any `class_user` subscription record.

#### Scenario: Unauthenticated user sees login link

- GIVEN an unauthenticated visitor loads `/clase/unirse/{code}`
- WHEN the page renders
- THEN a "Log in to join" link targeting `/admin/login` is displayed

#### Scenario: Authenticated user sees TBD placeholder with no subscription

- GIVEN an authenticated teacher visits `/clase/unirse/{code}`
- WHEN the page renders
- THEN a button labeled "TBD: join this class" is displayed
- AND clicking it does NOT insert any row into the `class_user` pivot table
- AND no subscription side-effect occurs

### Requirement: Nonexistent Invitation Code

The system MUST return HTTP 404 when the `invitation_code` does not match any existing class.

#### Scenario: Invalid code returns 404

- GIVEN no class has invitation_code "nonexistent"
- WHEN any user visits `/clase/unirse/nonexistent`
- THEN HTTP 404 is returned

### Requirement: Public Materials Section (Added in teacher-materials)

The public join page at `/clase/unirse/{invitation_code}` MUST render a "Materials" section after the existing TBD block. The section MUST display the class's study materials loaded via `$class->studyMaterials()->orderByDesc('created_at')->get()`.

#### Scenario: Materials section renders after TBD block

- GIVEN a class with invitation_code "abc12345" has 2 study materials
- WHEN a visitor loads `/clase/unirse/abc12345`
- THEN HTTP 200 is returned
- AND the response includes a "Materials" section that appears after the TBD placeholder block (not replacing it)

### Requirement: Material Rendering by Type (Added in teacher-materials)

Each study material MUST render according to its type:

| Type | Rendering Rule |
|------|---------------|
| FILE | Title as a download link pointing to the file URL |
| LINK (YouTube) | Responsive iframe embedding `https://www.youtube.com/embed/{11-char video ID}` |
| LINK (non-YouTube) | Plain `<a target="_blank" rel="noopener">` link to the URL |
| MEETING | Display `meeting_title`, formatted `scheduled_at`, and a "Join meeting" button linking to the meeting URL |

#### Scenario: FILE material renders as download link

- GIVEN a FILE material with title "Lecture Slides" and a valid file URL
- WHEN the Materials section renders
- THEN the title "Lecture Slides" is displayed as a download link to the file URL

#### Scenario: LINK with YouTube URL renders iframe

- GIVEN a LINK material with URL `https://www.youtube.com/watch?v=abc123def45`
- WHEN the Materials section renders
- THEN the 11-character video ID "abc123def45" is extracted via regex
- AND a responsive iframe embedding `https://www.youtube.com/embed/abc123def45` is rendered

#### Scenario: LINK with non-YouTube URL renders plain anchor

- GIVEN a LINK material with URL `https://example.com/resource`
- WHEN the Materials section renders
- THEN a plain `<a target="_blank" rel="noopener">` link to `https://example.com/resource` is rendered
- AND no iframe is generated

#### Scenario: MEETING material renders details with join button

- GIVEN a MEETING material with `extra_metadata` containing `meeting_title` "Live Session" and `scheduled_at` "2026-07-20T14:00:00", plus a meeting URL
- WHEN the Materials section renders
- THEN the `meeting_title` "Live Session" is displayed
- AND the `scheduled_at` is formatted for human-readable display
- AND a "Join meeting" button styled as a link points to the meeting URL

### Requirement: Materials Ordering (Added in teacher-materials)

Materials MUST be listed in `created_at DESC` order.

#### Scenario: Materials appear in reverse chronological order

- GIVEN a class has 3 materials created on July 1, July 2, and July 3
- WHEN the Materials section renders
- THEN materials appear in order: July 3 first, July 2 second, July 1 last

### Requirement: Empty Materials State (Added in teacher-materials)

When a class has no study materials, the Materials section SHOULD be hidden.

#### Scenario: No materials — section hidden

- GIVEN a class has zero study materials
- WHEN a visitor loads the join page
- THEN no "Materials" heading or section is rendered
