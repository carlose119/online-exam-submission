# class-invitation-flow Specification

## Purpose

Public (unauthenticated) route `clase/unirse/{invitation_code}` rendering class details via a Blade view outside the Filament panel. Auth-aware join affordance: authenticated users see a TBD placeholder (no actual subscription is created); unauthenticated users see a login link. The invitation link URL format is `https://{host}/clase/unirse/{invitation_code}`.

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
