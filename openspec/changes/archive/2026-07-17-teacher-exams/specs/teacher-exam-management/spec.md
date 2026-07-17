# teacher-exam-management Specification

## Purpose

Teacher-scoped exam CRUD via Filament v5 `ExamResource` under `/admin`. Single form with nested Repeaters: exam details → questions (`minItems:1`) → options (`minItems:2`). Query scope: classes owned by `Auth::id()`. SINGLE/MULTIPLE question types with type-switch notification, auto-calculated `max_score`, cascade delete, and preview action.

## Requirements

| # | Requirement | Key Rules |
|---|-------------|-----------|
| 1 | Exam Form with Nested Repeaters | Form fields: title, description (nullable), duration_minutes, max_score, questions Repeater (text, type Select, points, options sub-Repeater with text + is_correct Toggle). Validation: questions `minItems(1)`, options `minItems(2)`, ≥1 correct option per question. |
| 2 | Type Change Notification | `live()` on type Select → `afterStateUpdated` fires `Notification::warning()` to review is_correct flags. Does NOT auto-clear flags. |
| 3 | max_score Auto-Calc & Override | `mutateFormDataBeforeCreate` defaults max_score to sum(question points) when blank. Helper text: "Defaults to sum of question points." Overridable. Non-blocking warning if max_score < sum. |
| 4 | Teacher Query Scope | `getEloquentQuery()->whereHas('classroom', fn($q)=>$q->where('teacher_id',Auth::id()))`. class_id Select: searchable, teacher's classes only. Cross-teacher access → 404. |
| 5 | Table Display | created_at DESC order. Columns: title (searchable/sortable), classroom.title, duration_minutes Badge, max_score Badge, question count Badge (`withCount('questions')`), created_at. |
| 6 | Order Renumbering | `mutateFormDataBeforeSave` renumbers questions.order sequentially (1,2,3,…) from Repeater position. |
| 7 | Cascade Delete | onDelete('cascade') on both FKs. Confirmation modal on delete action. |
| 8 | Preview Action | Edit page header action: modal showing formatted JSON of all questions + options. |

### Scenario: Create exam with questions and options
- GIVEN authenticated teacher fills exam details and adds a question with 3 options (one correct)
- WHEN form submitted
- THEN exam, question, and options are persisted; exam appears in list

### Scenario: Form validation — minItems and correct-option rules
- GIVEN teacher submits form with 0 questions, OR a question with 1 option, OR a question with no correct option
- WHEN form submitted
- THEN validation fails with `minItems(1)` on questions, OR `minItems(2)` on options, OR "at least one correct option required"

### Scenario: Type switch fires warning, preserves flags
- GIVEN a SINGLE question with option A marked correct
- WHEN teacher switches type to MULTIPLE
- THEN Filament warning notification appears; option A's is_correct remains true

### Scenario: max_score auto-calculated from points and overridable
- GIVEN teacher adds 2 questions worth 5+10pts, leaves max_score blank
- WHEN form submitted → THEN max_score=15
- GIVEN teacher sets max_score=20 with questions summing to 15
- WHEN form submitted → THEN max_score=20 (override wins); warning if max_score < sum

### Scenario: Teacher A isolated from Teacher B's exams
- GIVEN Teacher B owns exam ID 42
- WHEN Teacher A views list → THEN exam 42 not visible
- WHEN Teacher A requests /admin/exams/42/edit → THEN HTTP 404
- WHEN Teacher A searches class_id Select → THEN only own classes appear

### Scenario: Table displays question-count Badge, newest first
- GIVEN exams created July 1 and July 3; July 3 exam has 5 questions
- WHEN list renders → THEN July 3 before July 1; Badge "5" in question count column

### Scenario: Order renumbered on save
- GIVEN teacher rearranges questions in Repeater
- WHEN exam saved → THEN questions.order = 1, 2, 3, … matching position

### Scenario: Cascade delete removes questions and options
- GIVEN exam with 2 questions, each with 3 options
- WHEN teacher confirms deletion → THEN exam, all question, and all option rows removed from DB

### Scenario: Preview renders exam JSON
- GIVEN teacher on Edit page
- WHEN clicking "Preview as student" → THEN modal shows questions+options as formatted JSON
