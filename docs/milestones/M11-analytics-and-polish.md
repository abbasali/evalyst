# M11 — Dashboard, Analytics & Exports

**Goal:** Give instructors an overview of the course, insight into how each question performed, CSV exports and AI spend. Also make it quick to reuse assessments, and polish the student pages for phones, accessibility and dark mode.

**Depends on:** M08, M10.
**Read first:** [features/results-and-exports.md](../features/results-and-exports.md), [03-ai.md](../03-ai.md) (cost tracking).

---

### M11.1 — Course dashboard

**Build**

- Replace the starter `pages/Dashboard.vue` content. Load the data in `DashboardController` with a few aggregate queries (no N+1).
- Cards:
    - **Active now:** open quizzes with live counts (started/submitted), open assignments with submitted/total
    - **Upcoming:** opens within 14 days
    - **Needs review:** count, linking to the inbox
    - **Recently closed:** results not yet released, each with a "Release" button
    - **Recent activity:** the last 10 audit logs and submissions
- Empty state (no assessments yet): links to "Add students", "Create questions", "Create quiz".

**Acceptance criteria**

- The counts are correct for a seeded course.
- The query count is bounded (assert with `DB::enableQueryLog`, fewer than 15 queries).
- Only the current course's data appears.

**Tests:** `tests/Feature/DashboardTest.php` (extend the existing one).

---

### M11.2 — Question analytics per quiz

**Build**

- `app/Queries/QuizQuestionStats.php`, computed over **graded** attempts. For each assessment question:
    - attempts answered
    - average score %
    - % with full marks (the difficulty index)
    - for choice questions, the option distribution: the count and % of attempts that selected each option, with the correct one highlighted
    - for open questions, the AI-vs-final score difference (how often the instructor overrode the AI)
- `pages/quizzes/Analytics.vue` (a tab next to Results) with simple bar visuals in Tailwind (no chart library). Flag questions with < 30% or > 95% full marks as "review this question".

**Acceptance criteria:** with a seeded quiz of known answers, the percentages are correct to 2 decimal places and the option counts add up to the number of attempts (for single choice).

**Tests:** `tests/Feature/Quizzes/QuizAnalyticsTest.php`.

---

### M11.3 — CSV exports

**Build**

- `GET …/assessments/{assessment}/export.csv` (one controller, streamed with `response()->streamDownload` and `fputcsv`, using lazy `cursor()` queries to keep memory low):
    - **Quiz:** roll number, name, status, started_at, submitted_at (course timezone), one column per question score, total, max, focus lost count.
    - **Assignment:** roll number, name, repo URL, commit SHA, submitted_at, minutes late, raw score, penalty, final score, one column per rule, status.
- `GET {current_team}/gradebook.csv`: one row per student × one column per published assessment (final score, or blank if no score), plus a total column. Unpublished or in-review cells are blank and noted in a header comment row.
- Export buttons on the results/submissions pages and the Students page.

**Acceptance criteria**

- The headers and values match the fixtures.
- Unpublished scores are left out.
- The response is streamed (a `StreamedResponse` instance).

**Tests:** `tests/Feature/Exports/CsvExportTest.php`.

---

### M11.4 — AI cost panel per course

**Build:** an "AI usage" card on the course settings page, or `pages/settings/AiUsage.vue`. It sums `ai_runs` for the current course, for this month and all time:

- total cost in USD
- cost and tokens by purpose
- failures
- the 10 most expensive runs (purpose, subject link, cost)

It also shows the configured model.

**Acceptance criteria:** the sums match the seeded `ai_runs`, and other courses' runs are excluded.

**Tests:** `tests/Feature/Settings/AiUsageTest.php`.

---

### M11.5 — Duplicate assessment

**Build:** `app/Actions/Assessments/DuplicateAssessment.php`.

- **Copies:** the settings, `assessment_questions` (same question IDs, marks, positions) or `assignment_rules`, and the access mode. It creates a new `public_id` and a new `shared_code`, sets `status = draft`, clears the dates, and names the copy "Copy of …".
- **Does not copy:** participants, attempts, submissions, or `results_released_at`.
- Button on the assessment Show page.

**Acceptance criteria:** the copy has no participants, the questions are shared (not duplicated), and the original is unchanged.

**Tests:** `tests/Feature/Assessments/DuplicateAssessmentTest.php`.

---

### M11.6 — Responsive, accessibility & dark-mode pass on student pages

**Scope:** `/join`, quiz landing, question screen, review-before-submit, confirmation, assignment page, results.

**Checklist**

- Usable at 360px width: the question map collapses into a sheet, and the timer stays visible (sticky).
- The code editor is usable on mobile, scrolling horizontally inside the editor without the whole page scrolling.
- Everything works from the keyboard: radio/checkbox groups with arrow keys, `←`/`→` to move between questions (ignored while typing in a field), and visible focus rings.
- The timer has `aria-live="polite"` for its warnings, and form errors are linked with `aria-describedby`.
- Colour contrast is WCAG AA in light and dark mode, and highlight.js themes are set for both.
- No layout shift when autosave status changes.

**Acceptance criteria:** the checklist is verified manually in the browser (record findings in the task note). The existing student feature tests still pass. `npm run types:check` passes.

**Tests:** no new automated tests are required. Optionally add a Pest browser smoke test if the Pest browser plugin is approved later.
