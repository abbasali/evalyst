# Progress

This is the **single place where progress is tracked**. Tasks are listed in the order they should be built. Each task ID links to its full spec in `docs/milestones/`.

**How to work through it** (also in `.ai/guidelines/evalyst.md`):

1. Pick the first unchecked task, unless the user names a different one.
2. Read its spec in the milestone file, the feature doc it links to, and any `decisions.md` entries it mentions.
3. Build it with tests. `composer test` must pass, and `npm run types:check` too if the frontend was touched.
4. Tick it here: `- [x]`. Add ` — note` only if something differed from the spec, and record real deviations in `decisions.md`.
5. Stop and report. Don't start the next task unless asked.

Legend: `[ ]` todo · `[x]` done · `[~]` in progress / partially done (explain in a note)

---

## M00 — Project setup → [milestones/M00-project-setup.md](milestones/M00-project-setup.md)

- [x] **M00.1** Scaffold Laravel 13 (Vue starter kit, teams, Pest, Boost, MySQL `evalyst`)
- [x] **M00.2** Local environment: `APP_NAME`, `APP_URL=http://evalyst.test`, git init
- [x] **M00.3** Spec docs, `PROGRESS.md`, Boost guideline and skills
- [x] **M00.4** Baseline tooling green: `composer test`, `npm run types:check`, `npm run build`; CI workflow uses MySQL — known: PHPStan crashes at 128M memory limit on a fresh scaffold (pass `--memory-limit=1G` in the `types:check` script)
- [x] **M00.5** `config/evalyst.php` skeleton + `.env.example` keys
- [x] **M00.6** First commit — Boost-generated files stay git-ignored (starter-kit default); `.ai/` is committed and `boost:update` regenerates them

## M01 — Instructors & courses → [milestones/M01-instructors-and-courses.md](milestones/M01-instructors-and-courses.md)

- [x] **M01.1** Rename "Team" to "Course" in all UI text (code names stay `Team`)
- [x] **M01.2** Turn off public registration; accepting an invitation creates the account — kept Fortify's register screen; it 404s without a valid invitation (D-015)
- [x] **M01.3** No personal teams; `evalyst:make-instructor` command; empty-dashboard "Create your first course" — onboarding at `/courses/start` (`pages/onboarding/Start.vue`)
- [x] **M01.4** Equal permissions for all members (D-003); hide role UI; owner-only delete
- [x] **M01.5** Course settings: description + timezone
- [x] **M01.6** `BelongsToCourse` trait, scoped instructor route group, cross-course isolation test helper — scoped bindings resolve children through the `Team` relation of the same plural name; no policy helper trait yet (policies added per resource)
- [x] **M01.7** Course-aware sidebar navigation skeleton (Dashboard, Question Bank, Quizzes, Assignments, Students, Review)
- [x] **M01.8** `audit_logs` table + `RecordAudit` action (used by every later manual override)

## M02 — Students roster → [milestones/M02-students.md](milestones/M02-students.md)

- [x] **M02.1** `students` migration, model, factory, policy — no `StudentPolicy`: membership middleware + scoped bindings already enforce access (404 for other courses)
- [x] **M02.2** Students index (search, pagination) + create/edit/delete — edit/create in a dialog, `resource` routes `index/store/update/destroy`
- [x] **M02.3** CSV import (name, roll_number, email) with a preview, then confirm

## M03 — Question bank → [milestones/M03-question-bank.md](milestones/M03-question-bank.md)

- [x] **M03.1** Enums, migrations, models, factories: questions, question_options, tags, question_tag
- [x] **M03.2** `<Markdown>` component (markdown-it + DOMPurify + highlight.js)
- [x] **M03.3** Question index: filters (type, tag, difficulty, source, needs_verification), search, pagination
- [x] **M03.4** Create/edit question form for all 4 types with validation per type — multiple choice: 3–8 options, ≥2 correct and ≥1 incorrect (feature doc); locked questions accept grading fields only (D-009)
- [x] **M03.5** Question preview (as the student will see it)
- [x] **M03.6** Tags: create inline, bulk-tag, rename/delete — tags managed in a dialog on the index (no separate page)
- [x] **M03.7** Locking (D-009) + duplicate question + soft delete — the "in a published assessment" delete guard lands with M05

## M04 — AI foundation & question generation → [milestones/M04-ai-question-generation.md](milestones/M04-ai-question-generation.md)

- [x] **M04.1** Install `laravel/ai`, configure OpenAI + model, `ai_runs` table, `RecordsAiRun`, `openai` rate limiter, fake-based test helpers — `laravel/ai` v1.0.1; `StructuredAgent` base reads provider/model from config; `OPENAI_STORE=false` so OpenAI doesn't retain prompts
- [x] **M04.2** `QuestionGenerator` agent + prompt file + schema + output validator
- [x] **M04.3** `QuestionVerifier` agent + key comparison
- [x] **M04.4** `question_generations` table + `GenerateQuestions` job (generate → validate → verify → store drafts)
- [x] **M04.5** Generation form UI (prompt, counts per type, difficulty, code-output toggle, tags) — form at `questions/generate`; limits: 3 running per course, 5 per instructor per 10 min
- [x] **M04.6** Draft review UI: polling, select/edit drafts, warnings for disputed answers, "Add selected to bank" — a disputed draft whose key/body the instructor edited is saved without `needs_verification`
- [x] **M04.7** Generation history list + retry failed — history lives on the generate page

## M05 — Quiz builder & access → [milestones/M05-quiz-builder.md](milestones/M05-quiz-builder.md)

- [x] **M05.1** Migrations/models/enums: assessments (shared + quiz columns), assessment_questions, participants — the `attempts` table, `Attempt` model and `AttemptStatus` enum are created here too (D-018)
- [x] **M05.2** Quiz CRUD + settings form (schedule, duration, shuffles, release mode, threshold) — each tab is its own page (`quizzes/Settings|Questions|Access`) inside `components/quizzes/QuizShell.vue`; list tabs: Open, Upcoming, Drafts, Closed, Archived
- [x] **M05.3** Question picker: add from bank with filters, reorder, override marks, totals — bank browser is a side sheet (JSON endpoint `quizzes.questions.bank`); marks in 0.5 steps
- [x] **M05.4** Access: roster mode (pick students → codes, regenerate, printable/CSV list)
- [x] **M05.5** Access: shared-code mode (generate/rotate code)
- [x] **M05.6** Publish checks + editing restrictions once attempts exist; archive — opens_at and track_focus are locked too once started; archive only from published; delete only drafts without participants (D-019)

## M06 — Quiz taking (student) → [milestones/M06-quiz-taking.md](milestones/M06-quiz-taking.md)

- [x] **M06.1** `routes/student.php`, `StudentLayout`, `/join` page, join throttling — throttle shows an inline error, not a 429 page (D-022)
- [x] **M06.2** `JoinAssessment` action: roster code / shared code + name + roll → participant; `EnsureStudentSession`
- [x] **M06.3** Quiz landing page (instructions, duration, question count) + `StartAttempt` (deadline, order snapshot, resume token)
- [x] **M06.4** Question screen: one at a time, prev/next, question map, flag; inputs for all 4 types (CodeMirror for code) — plus watermark, copy blocking, one-way navigation and fullscreen gate (D-021)
- [x] **M06.5** Autosave endpoint + server-side deadline enforcement + locking questions on first answer
- [x] **M06.6** Timer component synced to the server deadline, warnings, client-side auto-submit
- [x] **M06.7** Review-before-submit summary + `SubmitAttempt` + confirmation page with results link — results link 404s until M08.6
- [x] **M06.8** Resume rules (roster vs shared code), instructor "reset attempt"/"allow resume" — plus "Submit now" (force submit); all three are audit-logged
- [x] **M06.9** `attempts:expire` scheduled command (auto-submit)
- [x] **M06.10** Focus tracking → `attempt_events` — also paste and fullscreen-exit events (D-021); sent with `fetch(keepalive)` instead of `sendBeacon` (needs the CSRF header)
- [x] **M06.11** Instructor live monitor page (polling) — students in progress listed first; activity column flags 3+ focus losses/pastes/fullscreen exits
- [x] **M06.12** Instructor preview of the quiz in the student UI (no data saved) — separate `student/quiz/Preview` page reusing the question, map and timer components

## M07 — Quiz grading → [milestones/M07-quiz-grading.md](milestones/M07-quiz-grading.md)

- [x] **M07.1** `ChoiceScorer` (all 3 policies) with table-driven tests
- [x] **M07.2** `GradeAttempt` orchestration on submit: score choices, blank → 0, dispatch AI jobs, aggregate attempt status
- [x] **M07.3** `OpenAnswerGrader` agent + prompt (text and code) + injection hardening
- [x] **M07.4** `GradeOpenAnswer` job (rate limit, retries, failure state, ai_run logging) — retry/checkpoint details in D-024
- [x] **M07.5** `PublishGate` + auto-publish wiring
- [x] **M07.6** `grading:recover` scheduled command

## M08 — Review & results → [milestones/M08-review-and-results.md](milestones/M08-review-and-results.md)

- [x] **M08.1** Review inbox (course-wide): filters, counts in the sidebar badge
- [x] **M08.2** Review detail: answer vs model answer/rubric, AI score/feedback/confidence/flags; accept / edit & publish
- [x] **M08.3** Bulk publish, retry failed, regrade (one answer / whole question after a rubric change)
- [x] **M08.4** Audit trail UI per item (history panel in the review detail and participant page) + logging coverage check
- [x] **M08.5** Quiz results page (per participant), attempt detail view
- [x] **M08.6** Release results (manual/automatic) + student results page via `/results/{public_id}` — automatic release is an accessor; students never see model answers (D-025)

## M09 — Assignments: setup & submission → [milestones/M09-assignments.md](milestones/M09-assignments.md)

- [x] **M09.1** Migrations/enums: assignment columns, assignment_rules, submissions, submission_rule_results
- [x] **M09.2** Assignment CRUD form: statement, dates, late policy, resubmission, ignore paths, rule visibility
- [x] **M09.3** Rule builder (automated checks with config forms + AI rules, marks, ordering) — rules stay editable after grading (regrade from Submissions); graded rules can't be deleted (D-026)
- [x] **M09.4** `LatePenaltyCalculator` + effective deadline logic (pure, table-driven tests)
- [x] **M09.5** `GitHubClient` (repository, headCommit) + fixtures — tree/commits/fileContent written here too
- [x] **M09.6** Student assignment page + `SubmitRepository` action (validate, record SHA, lateness, resubmission)
- [x] **M09.7** Participant overrides UI + `ApplyParticipantOverride` (audit, recalculate score)
- [x] **M09.8** Instructor submissions list

## M10 — Assignment grading → [milestones/M10-assignment-grading.md](milestones/M10-assignment-grading.md)

- [x] **M10.1** `GitHubClient` tree/commits/fileContent (streamed) + rate-limit handling — methods landed in M09; tests here
- [x] **M10.2** `PathFilter` + `RepositoryIngestor` → `RepoSnapshot` + manifest
- [x] **M10.3** Automated checks (6 types)
- [x] **M10.4** `ProjectGrader` agent + prompt + validation
- [x] **M10.5** `GradeSubmission` job pipeline (ingest → checks → AI → penalty → PublishGate)
- [x] **M10.6** Submission review in the inbox: rule-by-rule override, regrade — submissions get their own section above quiz answers; regrade hides the published grade until settled (D-027)
- [x] **M10.7** Student assignment results view

## M11 — Dashboard, analytics & exports → [milestones/M11-analytics-and-polish.md](milestones/M11-analytics-and-polish.md)

- [ ] **M11.1** Course dashboard (active/upcoming assessments, pending reviews, recent activity)
- [ ] **M11.2** Question analytics per quiz (difficulty index, option distribution)
- [ ] **M11.3** CSV exports: assessment results, course gradebook
- [ ] **M11.4** AI cost panel per course
- [ ] **M11.5** Duplicate assessment
- [ ] **M11.6** Responsive + accessibility + dark-mode pass on student pages

## M12 — Deploy to Laravel Cloud → [milestones/M12-deploy.md](milestones/M12-deploy.md)

- [ ] **M12.1** Cloud app + MySQL + environment variables
- [ ] **M12.2** Managed Queues (`ai`, `default`) + scheduler
- [ ] **M12.3** Mail for invitations, production hardening (rate limits, error monitoring, backups)
- [ ] **M12.4** Production smoke-test checklist
