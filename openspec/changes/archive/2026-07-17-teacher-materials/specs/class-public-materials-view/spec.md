# Delta for class-invitation-flow — class-public-materials-view

## ADDED Requirements

### Requirement: Public Materials Section

The public join page at `/clase/unirse/{invitation_code}` MUST render a "Materials" section after the existing auth-aware TBD block. The section MUST display the class's study materials loaded via `$class->studyMaterials()->orderByDesc('created_at')->get()`.

#### Scenario: Materials section renders after TBD block

- GIVEN a class with invitation_code "abc12345" has 2 study materials
- WHEN a visitor loads `/clase/unirse/abc12345`
- THEN HTTP 200 is returned
- AND the response includes a "Materials" section that appears after the TBD placeholder block (not replacing it)

### Requirement: Material Rendering by Type

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

### Requirement: Materials Ordering

Materials MUST be listed in `created_at DESC` order.

#### Scenario: Materials appear in reverse chronological order

- GIVEN a class has 3 materials created on July 1, July 2, and July 3
- WHEN the Materials section renders
- THEN materials appear in order: July 3 first, July 2 second, July 1 last

### Requirement: Empty Materials State

When a class has no study materials, the Materials section SHOULD be hidden.

#### Scenario: No materials — section hidden

- GIVEN a class has zero study materials
- WHEN a visitor loads the join page
- THEN no "Materials" heading or section is rendered
