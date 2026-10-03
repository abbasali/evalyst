# M06 — Quiz taking (student)

**Goal:** students join with a code and take the quiz one question at a time. Navigation goes back and forth, answers autosave, and the timer is enforced on the server. They submit, or the quiz submits automatically. Instructors watch progress live and can reset attempts.

**Depends on:** M05.
**Read:** `features/quiz-taking.md`, `01-architecture.md` §Student side, `02-data-model.md` §attempts/answers/attempt_events.

---

### M06.1 — `routes/student.php`, `StudentLayout`, `/join`, throttling

**Build**

- Register `routes/student.php` in `bootstrap/app.php` (web middleware, **no** auth).
- `resources/js/layouts/StudentLayout.vue`: no sidebar. The course name and assessment title in the header, a slot for the timer, and a centred content width (max-w-3xl). Uses the existing appearance (dark mode) handling.
- `GET /join` → `pages/student/Join.vue`: a code input (uppercase, ignores spaces and dashes). In shared-code mode a second step asks for the name and roll number.
- `RateLimiter::for('join', 10 per minute per IP)` applied to `POST /join`.

**Acceptance criteria**

- The page works signed out. The 11th attempt in a minute gets 429 with a friendly message.

**Tests**

- Throttle test. Code normalisation (`ab cd-1234` → `ABCD1234`).

### M06.2 — `JoinAssessment` action + `EnsureStudentSession`

**Build**

- `POST /join` → `JoinAssessment`:
    - **Roster code:** look up `participants.access_code`, which identifies the participant.
    - **Shared code:** look up `assessments.shared_code`, then require `name` and `roll_number`. Find or create the course `Student` by the normalised roll number (keep the existing name; don't overwrite it), then find or create the `Participant`.
    - Reject the join with a clear message if the assessment is not published, not open yet ("Opens at …" in course timezone), closed, or archived.
    - On success: regenerate the session ID, store `student.participant_id`, set `joined_at`, and redirect to the landing page (`/a/{assessment:public_id}`).
- `App\Http\Middleware\EnsureStudentSession`: loads the participant from the session (otherwise redirects to `/join`), checks that the route's assessment or attempt belongs to it, and shares `student` and `assessment` props.
- Unknown codes always produce the same generic error, so codes can't be enumerated.

**Acceptance criteria**

- Both modes work. A second join with the same roll number in shared mode reuses the participant (resume rules are in M06.8). A participant from another assessment gets 403 on these routes.

**Tests**

- Dataset: roster ok, shared ok, unknown code, not open, closed, archived, roll-number normalisation reusing the student, session-mismatch 403.

### M06.3 — Landing page + `StartAttempt`

**Build**

- `GET /a/{assessment:public_id}` → `pages/student/quiz/Landing.vue`: the title, instructions (`Markdown`), duration, question count, total marks, closing time, and a notice about focus tracking if enabled. The button says **Start**, **Resume** or **View submission**, depending on the attempt's state.
- `POST /a/{assessment:public_id}/start` → `StartAttempt`, inside a transaction with a lock on the participant:
    - Refuse if an attempt already exists (resume follows M06.8).
    - `deadline_at = min(now + duration, closes_at)`
    - `question_order` = the assessment question IDs, shuffled if `shuffle_questions`
    - `option_order` = shuffled option IDs for choice questions if `shuffle_options`
    - `max_score` = snapshot of the total
    - create an `answers` row for every question (`grading_status=ungraded`, `max_score` = its marks)
    - `resume_token`: 64 random characters. Store the **hash**, and set the plain token as an httpOnly cookie `attempt_{public_id}` that lives until the deadline plus one day
    - store `student.attempt_id` in the session
- Redirect to `/attempts/{attempt:public_id}/q/1`.

**Acceptance criteria**

- Starting twice doesn't create a second attempt, even with two concurrent requests (the unique `participant_id` constraint is the backstop). The deadline is capped at `closes_at`.

**Tests**

- Deadline maths (with `travelTo` near `closes_at`), shuffling snapshot is stable across requests, answers pre-created, double start, concurrency (catch the unique-constraint error).

### M06.4 — Question screen

**Build**

- `npm i` the approved CodeMirror 6 packages. Add `components/code/CodeEditor.vue`: `v-model`, a `language` prop that maps `CodeLanguage` to a CodeMirror language, follows the theme, has line numbers and tab-to-indent, and is lazy-loaded with `defineAsyncComponent` so choice-only quizzes don't download it.
- `GET /attempts/{attempt:public_id}/q/{n}` → `pages/student/quiz/Attempt.vue`. The server sends the current question (body, options in snapshot order, **never `is_correct`**), the saved answer, a map summary (`[{n, answered, flagged}]`), `deadline_at`, and `server_now`.
- UI:
    - `QuestionView` in `answer` mode
    - a header with "Question n of N" and the timer
    - a **Flag for review** toggle
    - **Previous** / **Next** buttons
    - a **question map** (a side panel on desktop, a sheet on mobile) colour-coded as answered, flagged or current; clicking jumps to that question
    - keyboard shortcuts: ←/→ to navigate when focus isn't in an input
- Moving between questions uses Inertia visits with `preserveScroll`, saving first (see M06.5).

**Acceptance criteria**

- `is_correct`, `model_answer`, `rubric` and `explanation` never reach the student. Test this by inspecting the Inertia props. `n` outside 1..N returns 404.

**Tests**

- Prop-leak test for every question type. Navigation bounds. Another participant's attempt gives 403.

### M06.5 — Autosave, deadline enforcement, question locking

**Build**

- `PUT /attempts/{attempt:public_id}/answers/{assessmentQuestion}`, called via `useHttp` or fetch rather than an Inertia visit, so the page doesn't reload. Body: `selected_option_ids` | `text_answer` + `code_answer`, and `flagged`. The request is validated against the question type and the option IDs must belong to the question.
- `SaveAnswer` action:
    - Reject with 409 `{expired: true}` if the attempt is not `in_progress`, or if `now > deadline_at + config('evalyst.quiz.save_grace_seconds')` (30s).
    - Set `answered_at`.
    - If `question.locked_at` is null, set it (D-009).
- Client: `composables/useAutosave.ts` debounces 1.5s after typing, saves immediately before navigating or submitting, shows a "Saved ✓ / Saving… / Offline — retrying" indicator, and retries with backoff when the network fails. It keeps a copy in `localStorage` per attempt and question, sent again after reconnecting (wrap access in try/catch).
- A 409 response sends the student to the confirmation page.

**Acceptance criteria**

- Saves after the grace window are rejected. Saves within it are accepted. The question gets locked on the first save.

**Tests**

- Validation for each type, foreign option IDs rejected, grace boundary (`travelTo`), lock set, submitted attempt → 409.

### M06.6 — Timer

**Build**

- `components/student/QuizTimer.vue`:
    - Computes `offset = server_now - Date.now()` when the page loads, and counts down to `deadline_at` using that offset, so a wrong client clock doesn't matter.
    - Shows mm:ss (h:mm:ss when 1 hour or more).
    - Turns amber with a toast at **5 minutes** and red with a toast at **1 minute**.
    - At 0: flush autosave, then `POST submit` with `auto=true`.
    - Uses a single `setInterval(1000)` and re-syncs on `visibilitychange`.

**Acceptance criteria**

- With the client clock skewed by ±10 minutes, the timer still matches the server deadline (check by mocking `Date.now`). Warnings fire once each.

**Tests**

- Server side: `server_now` and `deadline_at` props are present in ISO UTC. Timer behaviour is checked with `npm run types:check` and a manual QA note.

### M06.7 — Review-before-submit + `SubmitAttempt` + confirmation

**Build**

- `GET /attempts/{attempt}/review` → `pages/student/quiz/Review.vue`: a grid of all questions marked answered or unanswered and flagged, with a warning listing unanswered and flagged numbers, then **Submit** with a confirmation dialog.
- `POST /attempts/{attempt}/submit` (`auto` boolean) → `SubmitAttempt`:
    - Idempotent: if already submitted, just redirect.
    - Set `status=submitted`, `submitted_at`, and `auto_submitted`.
    - Write an `auto_submitted` event if auto.
    - Dispatch `GradeAttempt` (defined in M07.2; until then a no-op job class).
    - Clear `student.attempt_id`.
- `GET /attempts/{attempt}/done` → `pages/student/quiz/Done.vue`: "Submitted", the submission time, and the **results link** `/results/{participant.public_id}` with a copy button and "Save this link to see your results later".

**Acceptance criteria**

- Submitting twice is harmless. After submitting, the question and save routes redirect to or return the done state.

**Tests**

- Submit, idempotency, dispatch (`Queue::fake`), routes after submission.

### M06.8 — Resume rules + instructor reset / allow resume

**Build**

- Resuming an `in_progress` attempt (from the landing page):
    - **Roster mode:** the code is the identity, so resuming is allowed from any device.
    - **Shared-code mode:** allowed only if the request carries the matching `attempt_{public_id}` cookie (compare against the hash). Otherwise show "This roll number already has a quiz in progress. Ask your instructor to allow resume."
    - Every resume writes a `resumed` event.
- Instructor endpoints on the Monitor tab (M06.11):
    - `POST participants/{participant}/allow-resume` sets `attempts.resume_override_until = now + 10 minutes`. Add this nullable column in this task's migration, and add it to `02-data-model.md`. The first resume inside that window, from any browser, generates a new `resume_token`, sets the new cookie, and clears the override. That makes the override single-use.
    - `POST participants/{participant}/reset-attempt` deletes the attempt and its answers, after a confirmation that requires typing the roll number, and writes an `attempt.reset` audit entry via `RecordAudit` (M01.8).
- Submitted attempts can never be resumed.

**Acceptance criteria**

- In shared-code mode, resuming from a different browser is blocked until the instructor allows it. The allow window expires.

**Tests**

- Matrix of mode × cookie × override window. Reset deletes the attempt and answers. Isolation for the instructor endpoints.

### M06.9 — `attempts:expire` scheduled command

**Build**

- `App\Console\Commands\ExpireAttempts` (`attempts:expire`): finds `in_progress` attempts where `deadline_at + grace < now`, in chunks, and calls `SubmitAttempt` with `auto=true` for each. Schedule it `everyMinute()->withoutOverlapping()` in `routes/console.php`.
- Also call the same check inline whenever a student request touches an expired attempt.

**Acceptance criteria**

- Expired attempts are auto-submitted exactly once. Attempts still in progress are untouched.

**Tests**

- Command test with `travelTo`. Idempotency.

### M06.10 — Focus tracking

**Build**

- When `track_focus` is on, `composables/useFocusTracking.ts` listens to `visibilitychange` and window `blur`/`focus`. It posts batched events to `POST /attempts/{attempt}/events` (at most one request per 5 seconds, using `sendBeacon` when the page unloads).
- The server validates the type (`focus_lost`/`focus_returned`), stores `attempt_events`, and increments `focus_lost_count`. It is rate-limited per attempt.
- Students see a small notice: "Leaving this tab is recorded." Nothing is blocked.

**Acceptance criteria**

- Events are recorded with server timestamps. Floods are throttled.

**Tests**

- Event storage, counter, throttle, rejected after submission.

### M06.11 — Instructor live monitor

**Build**

- The Monitor tab on `quizzes/Edit.vue` (`GET quizzes/{quiz}/monitor`) is polled with Inertia v3 `usePoll(5000)` while the quiz is open.
- One row per participant: name, roll, status, started at, time left, answered/total, flagged count, focus-lost count (highlighted at 3 or more), and whether it was auto-submitted.
- Actions per row: allow resume, reset attempt (M06.8).
- Summary counters at the top: joined, in progress, submitted, not started (roster mode).

**Acceptance criteria**

- The data refreshes without reloading the page. Polling stops when the quiz closes.

**Tests**

- Monitor props are correct for a mix of attempt states. Isolation.

### M06.12 — Instructor preview in the student UI

**Build**

- `GET quizzes/{quiz}/preview` renders the M06 student question screen (`pages/student/quiz/Attempt.vue`) in **preview mode**: an in-memory attempt (question order follows the shuffle setting), answers kept only in client state, the timer running locally, and Submit showing "Preview — nothing saved". A banner says "Instructor preview".

**Acceptance criteria**

- Nothing is written to `attempts` or `answers` during a preview (assert the database counts).

**Tests**

- The preview route returns 200 for a member, 403 for a non-member, and writes nothing.
