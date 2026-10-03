# M05 — Quiz builder & access

**Goal:** instructors build a quiz (settings, questions and marks), choose how students get in (roster codes or one shared code), check it with a preview, and publish it. Once students have started, the quiz is protected from edits that would break it.

**Depends on:** M02 (students), M03 (questions).
**Read:** `features/quiz-builder-and-access.md`, `02-data-model.md` §assessments/assessment_questions/participants, `decisions.md` D-008, D-011.

---

### M05.1 — Migrations, models, enums

**Build**

- Enums: `AssessmentType`, `AssessmentStatus`, `AccessMode`, `ReleaseMode`.
- Migrations:
    - `assessments` with **all** shared and quiz-only columns. The assignment-only columns are added in M09.1.
    - `assessment_questions` (unique `assessment_id + question_id`, `restrictOnDelete` on `question_id`)
    - `participants` (`public_id` ulid, `access_code` unique nullable, override columns can wait until M09.1, unique `assessment_id + student_id`)
- Models:
    - `Assessment` (`BelongsToCourse`, `SoftDeletes`, `HasUlids` for `public_id` only, using `uniqueIds()`)
        - relations: `assessmentQuestions()` ordered, `questions()` through the pivot, `participants()`
        - helpers: `isOpen()`, `isUpcoming()`, `isClosed()`, `maxScore()`
        - scopes: `quizzes()`, `assignments()`
    - `AssessmentQuestion` (a model rather than a bare pivot, because answers reference it)
    - `Participant`: `student()`, `assessment()`, `attempt()` (HasOne, added in M06)
- `App\Support\AccessCode::generate(int $length)` uses the alphabet `ABCDEFGHJKMNPQRSTUVWXYZ23456789` and `random_int`, and retries until the code is unique. Roster codes are 8 characters, shared codes 6.
- Add `Student::participants()`. Turn on the delete guards that were stubbed earlier: a student who has participants can't be deleted (M02.2), and neither can a question attached to a published assessment (M03.7).
- `AssessmentFactory` states: `quiz()`, `published()`, `open()`, `closed()`, `rosterMode()`, `sharedCode()`.

**Acceptance criteria**

- Migrations run. `isOpen()` and the other helpers are correct for each combination of status and dates (`opens_at` null = open as soon as published).

**Tests**

- Unit dataset for the time-state helpers using `travelTo`. Uniqueness and alphabet of `AccessCode`.

### M05.2 — Quiz CRUD + settings form

**Build**

- `Route::resource('quizzes', Instructor\QuizController::class)`. Route binding scopes to `type=quiz`, so an assignment ID returns 404.
- `StoreQuizRequest` / `UpdateQuizRequest`:
    - `title` required
    - `instructions` nullable Markdown
    - `opens_at` nullable, `closes_at` required, and `opens_at < closes_at`
    - `duration_minutes` 1–600
    - `shuffle_questions`, `shuffle_options`, `show_answers_after_release`, `track_focus` are booleans
    - `release_mode` is required
    - `auto_publish_threshold` nullable, 0.5–1.0
    - `access_mode` is required
- Datetime inputs are entered in the **course timezone** and converted to UTC in the request (`prepareForValidation`/`passedValidation`).
- Pages:
    - `pages/quizzes/Index.vue`: tabs for Draft / Scheduled / Open / Closed / Archived, with counts of participants and submitted attempts.
    - `pages/quizzes/Edit.vue`: a tabbed shell for Settings | Questions | Access | Monitor | Results. The tabs for later milestones show "Coming soon" until built.
- Create redirects to Edit → Questions.

**Acceptance criteria**

- Times entered in the course timezone are stored correctly in UTC and shown back in the course timezone.

**Tests**

- Validation dataset. Timezone round trip (course `Asia/Kolkata`). Isolation. An assignment ID on a quiz route returns 404.

### M05.3 — Question picker

**Build**

- The Questions tab:
    - an ordered list of `assessment_questions` with up/down reorder, an editable marks input (0.5–100), remove, and a running total of marks
    - an "Add questions" dialog that reuses the question index filters (type, tag, difficulty, search) with multi-select; questions already added are disabled
- Endpoints:
    - `POST quizzes/{quiz}/questions` (`question_ids[]`, which must be in the course and not deleted). `marks` defaults to `default_marks`, and new questions go at the end.
    - `PATCH quizzes/{quiz}/questions/order` (`ids[]`)
    - `PATCH quizzes/{quiz}/questions/{assessmentQuestion}` (marks)
    - `DELETE quizzes/{quiz}/questions/{assessmentQuestion}`
- Questions with `needs_verification` show a warning badge in the list.

**Acceptance criteria**

- Reordering keeps positions continuous (1..n). Question IDs from another course are rejected. The total equals `maxScore()`.

**Tests**

- Add, reorder, update marks, remove. Cross-course question IDs rejected. Duplicate add is ignored.

### M05.4 — Access: roster mode

**Build**

- The Access tab when `access_mode=roster`:
    - pick students from the course roster (search, "select all", a CSV import shortcut that links to M02.3)
    - `POST quizzes/{quiz}/participants` (`student_ids[]`) creates participants, each with an 8-character `access_code`
- Each participant row: name, roll number, code (with a copy button), status (not started / in progress / submitted, filled in once M06 exists), a **Regenerate code** action (`POST participants/{participant}/regenerate-code`), and remove.
    - Remove is blocked once an attempt exists.
- **Printable list:** `GET quizzes/{quiz}/codes/print` gives a plain printable Inertia page (name, roll, code, join URL `APP_URL/join`). `GET quizzes/{quiz}/codes.csv` downloads the same as CSV.

**Acceptance criteria**

- Codes are unique, 8 characters, and use only the alphabet. Regenerating stops the old code from working. Students from another course can't be added.

**Tests**

- Add participants, regenerate, remove guard, CSV content, isolation.

### M05.5 — Access: shared-code mode

**Build**

- The Access tab when `access_mode=shared_code`: shows the 6-character `shared_code` (generated when the mode is first set), a copy button, a **Rotate code** button, and the join URL.
- The participants list here is read-only: it fills up as students join (M06.2).
- Switching access mode is allowed only while there are no participants. Otherwise return 422 with an explanation.

**Acceptance criteria**

- Rotating the code stops the old one from working for new joins. Switching mode is blocked once participants exist.

**Tests**

- Generating on switch, rotate, mode-switch guard.

### M05.6 — Publish checks, edit restrictions, archive

**Build**

- `POST quizzes/{quiz}/publish` → `PublishAssessment` action. It checks that:
    - there is at least 1 question, and every question's marks > 0
    - `duration_minutes` is set
    - `opens_at < closes_at` and `closes_at` is in the future
    - roster mode has at least 1 participant, or shared mode has a shared code

    Failures come back as a validation error bag listing every problem.

- `POST quizzes/{quiz}/unpublish` is allowed only while there are no attempts.
- `POST quizzes/{quiz}/archive` / `unarchive`.
- **Restrictions once any attempt exists** (enforced in policies and requests, not only the UI):
    - Questions: no adding, removing or reordering. Marks can't be changed either, because that would change scores already given.
    - Settings: shuffle options, duration and access mode are locked. Title, instructions, `closes_at` (may be **extended** only), release mode and threshold can still be edited.
- The UI shows a lock banner that explains why.

**Acceptance criteria**

- Publishing fails with all reasons listed. Every restricted change returns 403 or 422 once an attempt exists, even through direct requests.

**Tests**

- Publish validation dataset. A restriction matrix (create an attempt with a factory, then try each change).

> The instructor preview was moved to **M06.12** because it reuses the student question screen built in M06.4.
