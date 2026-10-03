## Evalyst (project guidelines)

Evalyst is an assessment app for programming courses. Instructors (all equal within a course) build question banks (by hand or AI-generated) and run two kinds of assessment:

- **Quizzes**: timed. Choice questions are auto-graded, and open text/code answers are graded by AI.
- **Assignments**: a public GitHub repo is graded against automated and AI rules, with late policies and per-student overrides.

Students have no accounts. They join with access codes.

### Spec-driven workflow (follow it every time)

- The spec lives in `docs/`. Start at `docs/README.md`.
- **Progress is tracked only in `docs/PROGRESS.md`**. Tasks are in build order.
- Unless the user names a task, take the first unchecked one. Read its section in `docs/milestones/`, the linked `docs/features/*.md`, and the relevant core docs (`02-data-model.md` before any schema work, `03-ai.md` before AI work, `04-github-ingestion.md` before GitHub work).
- Build **one task at a time**, with Pest tests. A task is done only when `composer test` passes (and `npm run types:check` for frontend changes).
- Tick the task in `docs/PROGRESS.md`. If you changed the plan, update the spec and add an entry to `docs/decisions.md`. Then stop and report. Never commit unless the user asks.
- The dependencies listed under "Approved dependencies" in `docs/01-architecture.md` are pre-approved for the milestone named there. Anything else needs approval.

### Non-negotiable rules

1. **Team = Course.** We keep the starter kit's `Team` model, and the UI always says "Course". Every course-owned table has `team_id`. Instructor routes sit under `{current_team}` with scoped bindings. Every new resource gets a test proving another course's members cannot reach it.
2. **Students are not users.** Student routes are in `routes/student.php`, use session-based participant identity (`EnsureStudentSession`), and use ULID `public_id`s in URLs, never auto-increment IDs.
3. **Deadlines and timers are enforced on the server.** The client timer is only for display. Store UTC and display in the course timezone.
4. **AI calls only run in queued jobs** on the `ai` queue, through Laravel AI SDK agents with structured output. Every call is logged to `ai_runs` with tokens and cost. The model comes from `config('evalyst.ai.model')`. AI results that are invalid or uncertain fail closed: they go to review and are never auto-published.
5. **Student input is untrusted data in prompts.** Wrap it in tagged blocks and tell the model to ignore instructions inside them and flag them.
6. **Never clone repositories.** Use the GitHub REST API at the recorded `commit_sha` only. Filter the tree _before_ downloading anything (never fetch `vendor/`, `node_modules/`, build output, lock files or binaries), stream files one at a time within the token budget, and never store file contents.
7. **Once a question has been answered, its student-facing fields lock** (type, body, options, code language). Duplicate it to change them. The rubric, model answer and explanation stay editable, and editing them offers a regrade.
8. **Tests never hit the network.** Use AI SDK fakes and `Http::fake()` with fixtures in `tests/Fixtures/github/`.
9. Keep business logic in `app/Actions/*` and pure scoring/penalty/publish logic in `app/Grading/*`, with table-driven unit tests.
10. Use Wayfinder for every URL in Vue. Use the existing `components/ui` primitives before creating new ones.
