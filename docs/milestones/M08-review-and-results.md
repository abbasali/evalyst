# M08 — Review & Results

**Goal:** Instructors review every AI grade that wasn't published automatically, or that failed, from one course-wide inbox. Every manual change is audited. Instructors release results, and students see them through their private results link.

**Depends on:** M07.
**Read first:** [features/grading-and-review.md](../features/grading-and-review.md), [features/results-and-exports.md](../features/results-and-exports.md), [02-data-model.md](../02-data-model.md) (`answers`, `audit_logs`, `participants.public_id`).

---

### M08.1 — Review inbox (course-wide)

**Goal:** One list of everything that needs a decision.

**Build**

- Route `GET {current_team}/review` → `Instructor\ReviewController@index` → `pages/review/Index.vue`.
- Source rows:
    - answers with `grading_status ∈ {needs_review, failed}`
    - from M10 on, submissions with `status ∈ {needs_review, failed}`

    Merge them in a small query object, `app/Queries/ReviewInboxQuery.php`, that returns a normalised row: kind, assessment, participant, question/rule summary, AI score, confidence, flags, status, age.

- Filters: assessment, question, status, flag. **Group by question** toggle, so the instructor can grade one question across all students in one go.
- Sidebar badge: the count of pending review items. It's shared through `HandleInertiaRequests` as a lazy prop and cached for 30s per course.

**Acceptance criteria**

- Only items from the current course appear (cross-course test).
- Filters work.
- The badge count matches the number of rows.

**Tests:** `tests/Feature/Review/ReviewInboxTest.php`.

---

### M08.2 — Review detail: accept / edit & publish

**Goal:** Decide on one answer quickly.

**Build**

- `pages/review/Answer.vue`. Left side: the question (Markdown), the model answer, the rubric. Right side: the student's text and code (read-only CodeMirror or highlighted), then the AI score, feedback, breakdown, confidence and flags.
- Actions:
    - **Accept AI grade** → `PublishGrade` action (score = ai_score, feedback = ai_feedback).
    - **Edit & publish** → a score (0–max, step 0.5) and feedback text.

    Both set `final`, `graded_by`, `published_at`, write an audit log (M08.4), and call `RefreshAttemptScore`.

- **Next item** button (keyboard `j`/`k`) that keeps the current filters.

**Acceptance criteria**

- Accepting moves the answer to `final`, and the attempt becomes `graded` once it was the last item.
- A score outside the range is rejected (Form Request).
- Instructors from another course get a 404.

**Tests:** `tests/Feature/Review/ReviewAnswerTest.php`.

---

### M08.3 — Bulk publish, retry failed, regrade

**Build**

- **Bulk publish:** select rows in the inbox, then "Accept AI grades". It only applies to `needs_review` rows that have a valid `ai_score`. Rows that are `failed` are skipped and reported.
- **Retry:** for `failed` items, reset to `pending` and dispatch the job again.
- **Regrade:**
    - **One answer:** reset to `pending`, clear the AI fields, and dispatch again.
    - **Whole question** (after the instructor edits the rubric via "duplicate"/unlock flow, or changes `assessment_questions.marks`): every non-blank open answer for that assessment question.

    If any target is already published, the UI asks for confirmation: _"N grades are already published; students may see scores change."_ Regrading never clears `published_at` until the new grade is applied. A regraded answer whose new result needs review keeps showing its old published score to students until the instructor decides.

**Acceptance criteria**

- Bulk publish of 3 rows → 3 audit logs.
- Retry dispatches 1 job.
- A question-wide regrade dispatches one job per non-blank answer.

**Tests:** `tests/Feature/Review/BulkAndRegradeTest.php`.

---

### M08.4 — Audit trail UI + logging coverage

**Build**

- The `audit_logs` table and `RecordAudit` already exist (M01.8). Here, make sure every manual action calls them: `grade.accept`, `grade.override`, `grade.bulk_accept`, `grade.regrade`, `question.rubric_update`, `attempt.reset`, `attempt.allow_resume`, `attempt.force_submit`, `results.release`, `results.unrelease`. M09 adds `participant.override` and `submission.regrade`.
- A reusable `components/audit/AuditTrail.vue` panel (who, when, action, before → after, note). Show it on the attempt detail page (M08.5), on the review detail (M08.2), and later on the submission detail page (M10.6).

**Acceptance criteria:** every action in M08.2 and M08.3 writes exactly one log with before/after scores, and the panel lists them newest first.

**Tests:** a coverage test that runs each manual action and asserts one matching `audit_logs` row. A panel props test.

---

### M08.5 — Quiz results page + attempt detail

**Build**

- `GET {current_team}/quizzes/{assessment}/results` → `pages/quizzes/Results.vue`: one row per participant with name, roll number, status, score/max, submitted time, focus-loss count, and the count of pending reviews.
- `GET {current_team}/attempts/{attempt}` → `pages/attempts/Show.vue`: each question with the student's answer, correct options highlighted, score, feedback, grading status and an inline "Edit grade" button (which reuses the M08.2 action), plus the focus events timeline and the audit trail.

**Acceptance criteria**

- The totals match the sum of answer scores.
- Participants who never started appear as "Not started".

**Tests:** `tests/Feature/Quizzes/QuizResultsTest.php`.

---

### M08.6 — Release results + student results page

**Build**

- **Release modes** (`assessments.release_mode`):
    - `manual`: "Release results" and "Unrelease" buttons set or clear `results_released_at` (audited).
    - `automatic`: results count as released once the assessment is closed (`now > closes_at`). A scheduled job or an accessor sets `results_released_at` at close time. The accessor is preferred: `Assessment::resultsReleased()`.
- **Student page:** `GET /results/{participant:public_id}` (student routes, no session needed, `throttle:60,1`) → `pages/student/Results.vue`.
    - Not released → "Results are not available yet."
    - Released → for each item: published ones show the score and feedback. Unpublished ones show **"Under review"**.
    - The **total is shown only when every item is published**. Otherwise it shows "Total will appear once all answers are reviewed."
    - If `show_answers_after_release` is on, show the correct options and the explanation for choice questions, and the model answer for open questions.
- The confirmation page (M06.7) and the roster code page link here.

**Acceptance criteria**

- An unknown ULID → 404.
- Unpublished answers never leak their score or feedback (assert on the Inertia props, not just the UI).
- The automatic mode releases after `closes_at` (`travelTo`).

**Tests:** `tests/Feature/Student/ResultsPageTest.php`.
