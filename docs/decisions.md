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
