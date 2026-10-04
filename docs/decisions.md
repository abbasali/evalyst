# Decision Log

This file records decisions that cannot be worked out from the code. Add a new entry whenever a task changes the plan or settles an open question. Newest entries go at the bottom.

Format: `D-NNN — Title` · date · decision · reason.

---

**D-001 — Laravel 13 + Vue starter kit with teams** · 2026-10-03
The project was scaffolded with `laravel new --vue --teams --pest --database=mysql --boost`. The stack is Inertia v3, Vue 3, TypeScript, Tailwind v4, shadcn-vue style components (`resources/js/components/ui`), Fortify, Wayfinder, Pest 5 and Larastan level 7.
_Why:_ this is the instructor's chosen stack, and the teams scaffold gives us multi-instructor membership and invitations without writing them ourselves.

**D-002 — A Team is a Course** · 2026-10-03
We keep the starter kit's `Team` model, tables and routes, and call it **Course** everywhere in the UI. Every course-owned table has a `team_id` foreign key.
_Why:_ renaming the scaffold would mean fighting the starter kit on every upgrade. Mapping the concept is cheap.

**D-003 — All instructors in a course are equal** · 2026-10-03
Every member can do everything inside the course. The only exception is deleting the course itself, which stays with the owner (the creator). Role selectors are hidden in the UI, and invitations always use the `admin` role.
_Why:_ the instructor wants a single role for now. Keeping the starter kit's role system underneath lets us add roles later.

**D-004 — Invite-only accounts, no personal teams** · 2026-10-03
Fortify public registration is turned off. New instructors either accept an invitation (which creates their account) or are created with `php artisan evalyst:make-instructor`. No personal team is created for a user.
_Why:_ this is a closed teaching tool, and a "personal course" would mean nothing.

**D-005 — OpenAI `gpt-5.4-mini` for every AI task** · 2026-10-03
All agents (question generation, question verification, open-answer grading, project grading) use one model set in `config/evalyst.php` (`EVALYST_AI_MODEL`), called through the Laravel AI SDK (`laravel/ai`). Confirm the exact model ID when M03 starts.
_Why:_ it is cheap (about $0.0025 per graded answer and about $0.05 per graded project) and accurate enough. Because the provider is behind the SDK, switching later is a config change.

**D-006 — Jev is not used** · 2026-10-03
TypeSafe AI's Jev was considered as the gate for auto-publishing and rejected for v1. It cannot generate feedback, its reported accuracy is about 68%, it is in early access with no PHP SDK, and the vendor is new. The auto-publish decision sits behind `PublishGate`, so Jev could be added later as a second opinion.

**D-007 — Never clone repositories** · 2026-10-03
Repos are read only through the GitHub REST API, at the commit SHA recorded when the student submits. The file tree is filtered _before_ any file content is downloaded, so dependency and build folders (`vendor/`, `node_modules/` and so on) are never fetched. File contents are streamed one at a time under a token budget and are never written to disk or stored in the database.
_Why:_ this keeps memory use low on Laravel Cloud workers and gives grading that can be reproduced.

**D-008 — One `assessments` table for quizzes and assignments** · 2026-10-03
Quizzes and assignments are always separate assessments, but they share most of their lifecycle: course, title, schedule, access mode, participants, release of results. One table with a `type` column, plus nullable type-specific columns, keeps participants and access handling in one place. Quiz data lives in `attempts`/`answers`; assignment data lives in `submissions`/`submission_rule_results`.

**D-009 — Student-facing parts of a question lock once it is answered** · 2026-10-03
Once an answer is recorded against a question (`locked_at`), its **student-facing** fields cannot be edited: `type`, `body`, `options` (text and which are correct), and `code_language`. To change those, duplicate the question and edit the copy.
The **grading-side** fields stay editable: `model_answer`, `rubric`, `explanation`, `default_marks`, `difficulty`, tags. Saving a change to `model_answer` or `rubric` on a locked question offers "Regrade affected answers", and that change is audit-logged.
_Why:_ students must always be graded against exactly what they saw, while instructors still need to fix a weak rubric after an exam. This avoids building question versioning.

**D-010 — Polling instead of websockets** · 2026-10-03
Screens showing "generating…" or "grading…" use Inertia v3 polling (`usePoll`). We do not use Reverb in v1.
_Why:_ there is less infrastructure on Laravel Cloud, and the update speed is fine for these screens.

**D-011 — Course-level student roster** · 2026-10-03
Students belong to the course (`students` table: name and roll number, where the roll number is unique per course). Each participant in an assessment points to a student. In shared-code mode, joining finds or creates the student by roll number.
_Why:_ it gives a gradebook for the whole course and lets one roster be reused across assessments.

**D-012 — Tests run on MySQL, not SQLite** · 2026-10-03
Both `phpunit.xml` and CI use a MySQL database called `evalyst_test`. It is set up in M00.4.
_Why:_ the app relies on JSON columns, ULIDs, decimal arithmetic and datetime comparisons. Those behave slightly differently in SQLite, and we want tests to match production on Laravel Cloud MySQL.

**D-013 — Audit logging arrives early; preview moves later** · 2026-10-03
The `audit_logs` table and the `RecordAudit` action move to **M01.8**, so every manual override from M06 onwards is audited without a stopgap. The instructor quiz preview moves from M05.7 to **M06.12** because it reuses the student question screen built in M06.4.

**D-014 — Two queues only: `ai` and `default`** · 2026-10-03
GitHub ingestion runs inside `GradeSubmission` on the `ai` queue, so a separate `github` queue isn't needed. GitHub rate limits are handled by `release()`-ing the job (see 04-github-ingestion.md).

**D-015 — Invite-only sign-up reuses Fortify's register screen** · 2026-10-03
Fortify registration stays enabled, but `/register` returns 404 unless `?invitation=<code>` is a pending invitation. `CreateNewUser` requires the code, forces the invitation's email, marks the user verified, creates the membership and accepts the invitation in one transaction. The invitation mail links to register (no account yet) or to login (an account exists).
_Why:_ it's less code than a separate `InvitationRegistrationController`, and the starter kit's register page already shows invitation context.

**D-016 — Every factory user owns a regular course** · 2026-10-03
`UserFactory` creates a non-personal course owned by the user. `User::factory()->withoutCourse()` skips this. `personalTeam()` is removed. When a user loses their current course (removed, left, deleted), they fall back to another course, or to none, in which case they land on `/courses/start`.

**D-017 — AI SDK specifics** · 2026-10-04
We use `laravel/ai` v1.0.1. Agents extend `App\Ai\Agents\StructuredAgent`, whose `provider()`/`model()` methods read `config('evalyst.ai.*')`, and whose instructions live in `resources/prompts/*.md`. `config/ai.php` sets `OPENAI_STORE=false` by default, so OpenAI doesn't keep prompts that contain student work. The model ID `gpt-5.4-mini` and its price ($0.75/$4.50 per 1M tokens) come from public pricing pages as of 2026-10. Check them against the OpenAI dashboard before going live.

**D-018 — The `attempts` table is created in M05** · 2026-10-04
M05.1 also creates the `attempts` table (columns as in 02-data-model.md), the `Attempt` model/factory and `AttemptStatus`. M06 builds the student flow on it.
_Why:_ M05.6 must lock a quiz "once any attempt exists", and its restriction tests need real attempt rows. Creating the table early is simpler than a stand-in flag.

**D-019 — Quiz lifecycle details** · 2026-10-04

- Once a student has started, these settings are locked in addition to the spec's list: `opens_at` and `track_focus`. Allowed: title, instructions, extending `closes_at`, release mode, show-answers, threshold, adding roster students.
- Archived quizzes are read-only (settings, questions, access). Only a published quiz can be archived, and not while students may still be mid-attempt (it must be closed or have no attempts). Unarchiving returns it to `published`.
- A published quiz keeps passing its publish checks: it can't lose its last question or last roster student, and its access mode can't change (move it back to draft first).
- Every change to a quiz's question list locks the assessment row and re-checks "no attempts" inside the transaction. M06's `StartAttempt` must lock the same row.
- Only a draft with no participants can be deleted (soft delete). Anything else is archived instead.
- Switching access mode is blocked once any participant exists (stricter than "has started"), because roster codes and shared-code joins can't be mixed. Switching back to roster clears the shared code.
- A bank question that is soft-deleted while in a draft quiz blocks publishing ("Remove questions deleted from the bank").
- The quiz edit screen is one Inertia page per tab (`quizzes/Settings`, `quizzes/Questions`, `quizzes/Access`) sharing `components/quizzes/QuizShell.vue`, so each tab has its own URL. Monitor and Results show "soon" until M06/M08.

**D-020 — Access codes avoid every look-alike character** · 2026-10-04
The code alphabet is `ACDEFHJKMNPRTWXY3479` (20 characters). Both sides of each confusable pair are left out: `0/O/Q`, `1/I/L`, `2/Z`, `5/S`, `6/G`, `8/B`, `U/V`. Codes stay 8 characters (roster, ~2.6×10¹⁰ combinations) and 6 (shared, ~6.4×10⁷), which is plenty with unique checks and join rate limiting.
_Why:_ codes are read off printed cards and projectors and typed by students in a hurry. Leaving out only one side of a pair (e.g. dropping `O` but keeping `Q`) still causes wrong entries.

**D-021 — Anti-cheating measures** · 2026-10-04
The instructor picked these from a list of options:

- **Watermark** (always on): the quiz screen shows the student's name and roll number, faint and repeated, so photos and screenshots can be traced.
- **No copying question text** (always on): question and option text can't be selected, copied, cut, dragged or right-clicked. This is a deterrent only.
- **One-way navigation** (`assessments.one_way_navigation`, default off): no Previous button, no flagging, and the map only allows the next question. The server enforces it through `attempts.furthest_position`: earlier questions redirect, saves to them return 409, and the review page needs the last question to have been reached.
- **Require fullscreen** (`assessments.require_fullscreen`, default off): an overlay hides the quiz until the browser is fullscreen. Browsers without the Fullscreen API (iPhone Safari) are let through. Each exit is logged as a `fullscreen_exited` event (M06.10).
- **Paste detection** (part of `track_focus`): pasting into a text or code answer logs a `pasted` event with the pasted length. Nothing is blocked. It shows on the live monitor (M06.11) and, later, in the review inbox (M08).
  Both new settings are locked once a student has started.
  Not adopted for now: question pools, answer-similarity checks, timing flags, IP flags, a start PIN, question variants, lockdown browsers and webcam proctoring.

**D-022 — Student flow details** · 2026-10-04

- URLs: landing `/a/{public_id}`, question `/attempts/{public_id}/q/{n}`, plus `/review`, `/submit` and `/done` under the attempt.
- The join throttle answers with an inline form error ("Too many tries…") instead of a bare 429 page, because the student is in the middle of typing a code. It is 10 per minute per **browser session**, plus a ceiling of 300 per minute per IP (`join_attempts_per_ip_per_minute`). A plain per-IP limit would lock out a class that shares one campus IP.
- Autosave addresses answers by **position** (`PUT /attempts/{id}/answers/{n}`), and options are sent as their **displayed index**, never as database IDs. Sorting IDs would otherwise reveal the authored option order and undo the shuffle.
- The client keeps one save queue per attempt. It never leaves a question, or submits, while an answer is unsaved. A local copy is restored only if it was edited after the server's last save. A 401/409 from a save (session gone, time up, question closed, continued in another browser) stops saving and redirects with a message.
- A roster code must belong to a roster-mode quiz, and a shared code to a shared-mode quiz. Codes of the wrong length never match.
- Resuming in shared-code mode uses the `attempt_{public_id}` cookie. Every attempt route checks it, not only the landing page, so a second browser that joins with the same roll number can't continue the attempt by URL.

**D-023 — Live monitor and preview details** · 2026-10-04

- Monitor row actions: **Allow resume on another device** (shared-code mode only: a 10-minute, single-use window), **Submit now** (force submit, `force_submitted` event) and **Reset attempt** (the instructor types the roll number to confirm). All three write audit logs on the participant.
- Reset is allowed only while the quiz is open. After it closes, a reset would delete the submission with no way to start again. A reset student's open tab gets a 409 `reset` (or a redirect) on its next request and returns to the landing page.
- These actions often race a student because the monitor polls, so a stale action shows an explanatory toast instead of an error page.
- Activity events are posted in batches at most every 5 seconds, using `fetch` with `keepalive` rather than `sendBeacon`, because the request needs Laravel's CSRF header. They're limited to 30 requests a minute per attempt.
- The preview is its own page (`student/quiz/Preview`) that reuses the student question, map and timer components with answers kept in memory. It shows no fullscreen gate. A banner says fullscreen is required for students.

**D-024 — Quiz grading details** · 2026-10-04

- `GradeOpenAnswer` follows the M04 job pattern rather than `tries = 3`: `maxExceptions = 3`, `retryUntil` 6 hours (a large class can wait behind the `openai` rate limiter for a while), backoff 30/120/300s, timeout 180s. A `SkipUnlessAnswerPending` middleware runs before the overlap lock and rate limiter, so duplicates from `grading:recover` cost nothing.
- Invalid AI output (`InvalidAiOutput`) marks the answer `failed` straight away with the reason in `grading_error`, and the job ends normally instead of calling `$this->fail()`. The `ai_run` still counts as a successful call, because the tokens were billed.
- The validated AI suggestion is saved before the publish gate runs, so a retry after a database error doesn't pay for a second AI call.
- The gate uses the raw confidence. `answers.ai_confidence` stores it rounded **down** to 2 decimals, so 0.795 never looks like 0.80.
- `attempts.score` stays null until every answer is final. When a regraded answer goes to review, it keeps its earlier published score and `published_at` until the instructor decides.
- `grading:recover` also settles attempts left in `grading` with no pending answers, and it re-queues lost `GradeAttempt` jobs.

**D-025 — Review and results details** · 2026-10-04

- Automatic release is worked out by `Assessment::resultsReleased()`: closed, and nobody still mid-attempt. No `assessments:auto-release` command. Manual mode stamps `results_released_at`. Unrelease exists only in manual mode.
- Students never see model answers or rubrics, even with `show_answers_after_release` on (feature doc rule; M08.6 said to show the model answer). With it on, they see the correct options and explanations.
- `{answer}` and `{attempt}` instructor routes are resolved through `Team::resolveChildRouteBinding()`, scoped by the assessment's course, so another course gets a 404.
- Regrading writes one `grade.regrade` audit log **per answer**, including question-wide regrades. Choice answers are rescored at once (e.g. after a scoring policy change). Open answers go back to the AI, and their published grade stays visible until the new one is settled.
- The question edit page offers "Regrade N answers" on locked questions. Editing a locked question's model answer, rubric or scoring policy writes `question.rubric_update`.
- Only the instructor actions in the review inbox clear the sidebar badge cache. Changes made by jobs show up within 30 seconds.

**D-026 — Assignment details** · 2026-10-05

- Routes use `{assignment}`, bound explicitly in `AppServiceProvider` (scoped to the course slug in the URL, assignments only). Laravel passes already-bound models to controllers by position, so the quiz controllers for access, status and results release serve assignments too, with no duplicate controllers. The quiz Access page became `components/assessments/AccessPanel.vue`, shared by both.
- The assignment tabs are Settings, Rules, Access and Submissions. Release controls live on Submissions.
- Rules can be edited at any time. Saving doesn't regrade by itself. A rule with graded results can't be deleted.
- `submissions.ai_flags` (json) was added for `ProjectGrader` flags.
- An assignment is always reachable by its code once published, even after the deadline. The page explains whether the student can still submit, and shows their history and results link. A resubmission after the deadline needs a `late_override = allow`.
- Changing the deadline or late policy recalculates every current submission's lateness, penalty and score (`RecalculateLatePenalty`). So do participant overrides.
- `GitHubClient` got all its methods in M09: `repository`, `headCommit`, `tree`, `commits` and `fileContent` (streamed and capped).
- A participant's fixed `penalty_override` applies only to **late** submissions (the feature doc's "only when late" wins over M09.4's ordering). Waiving still beats the override.
- Submitting calls GitHub with a short timeout (8s, no retries) through `GitHubClient::quick()`, so the student gets a clear message instead of a timeout. Lateness uses the moment the student pressed submit. Grading jobs keep 15s with 3 tries. An empty repository (409) gets its own message.
- A student who has submitted can't be removed from an assignment, because the cascade would delete their submissions.

**D-027 — Assignment grading details** · 2026-10-05

- `GradeSubmission` uses the same retry pattern as D-024: `maxExceptions = 3`, `retryUntil` 6h, timeout 300s. `ProjectGrader` times out at 240s. A GitHub rate limit `release()`s the job until the reset time. A repo that has since become private or been deleted fails with a clear message. Invalid AI output (a missing, unknown or duplicate rule id, or a score out of range) marks the submission `failed` straight away.
- The token budget is counted from the tree's blob sizes (`ceil(bytes/4)`), so files are skipped **before** they are downloaded. Smaller files that come later can still fit after a big one is skipped.
- `max_score` is recalculated from the rules when grading, because rules stay editable after submission.
- Regrading a submission (one, or "Regrade all") clears `published_at`. The student sees "Under review" until the new grade is settled, which avoids showing new per-rule reasoning next to an old total. Quiz answers keep their old published score instead (D-025).
- The review inbox lists submissions in their own section above quiz answers, instead of merging both into one paginated list.
- In automatic release mode, assignment results wait for the latest participant deadline override.
- Each grading run first deletes the submission's old rule results. A failed run can't leave old AI scores next to new automated ones, and a failed submission can't be published until grading succeeds.
- The review form sends only the rule scores the instructor changed. AI and partial automated scores don't need to be in 0.5 steps. Publishing checks scores against the rules' **current** marks, and recalculates `max_score`.
- `ProjectGrader` puts everything from the repository (commit messages, paths and files) in one `<repository>` block. Messages and paths are collapsed to a single line.
- `PathFilter` matches ignore patterns case-insensitively. A trailing `/` or a plain folder name in the extra ignore paths covers the whole folder. Common secret files (`*.pem`, `*.key`, `id_rsa*`, `auth.json`, `.npmrc`) are never fetched.
