# 05 — Testing

Every task ships with tests. A task is **done** only when:

```bash
composer test            # config:clear + Pint check + PHPStan (level 7) + Pest
npm run types:check      # whenever resources/js was touched
```

Both must pass. CI (`.github/workflows/tests.yml`, `composer ci:check`) runs the same checks plus `npm run check`.

## Database

- The starter kit's `phpunit.xml` uses SQLite in memory. **M00.4 switches tests to MySQL** (`DB_CONNECTION=mysql`, `DB_DATABASE=evalyst_test`), because we rely on MySQL behaviour for JSON columns, `decimal` precision, and unique indexes across nullable columns. Create the database locally once with `mysql -uroot -e "CREATE DATABASE evalyst_test"`. CI adds a `mysql:8` service.
- Use `RefreshDatabase` (already set up in `tests/Pest.php` for Feature tests). Unit tests for pure classes don't touch the database.

## Layout

```
tests/
├── Feature/<Area>/...Test.php   # HTTP + actions + jobs (Assessments, Grading, Review, Student, GitHub, Ai, Jobs, Exports…)
├── Unit/Grading/...Test.php     # Pure classes: ChoiceScorer, PublishGate, LatePenaltyCalculator
├── Unit/GitHub/...Test.php      # RepoUrl, PathFilter, Checks/*
├── Fixtures/github/*.json       # Canned GitHub API responses (+ raw file bodies)
└── Support/                     # Helpers: SnapshotBuilder, course helpers
```

## Conventions

- Use Pest syntax (`it()` / `test()`, `expect()`), following the existing starter-kit tests. Name tests by behaviour: `it('auto-submits attempts past their deadline')`.
- **Pure logic** (`app/Grading/*`, `PathFilter`, `Checks/*`, `RepoUrl`) uses **datasets** with one row per rule or edge case, especially boundary values (exactly at the deadline, at the threshold, at the cap).
- **HTTP** tests assert on the Inertia response:

    ```php
    $this->get(route('questions.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('questions/Index')
            ->has('questions.data', 3));
    ```

    For anything a student sees, assert on the **props**, not just the status code, so hidden data (unpublished scores, correct options during a quiz) is proven to be missing.

- Use Wayfinder or `route()` helpers in tests. Never hard-code URLs.
- Call Actions directly in feature tests when the HTTP layer adds nothing.

## Factories (with states)

Every model gets a factory. States we expect to use:

| Factory          | States                                                                                                                                                                |
| ---------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `Team`           | (starter kit)                                                                                                                                                         |
| `Student`        | —                                                                                                                                                                     |
| `Question`       | `singleChoice()`, `multipleChoice(policy)`, `openText()`, `openCode(lang)`, `fromAi()`, `locked()`, `needsVerification()`. Choice states create their options too.    |
| `Assessment`     | `quiz()`, `assignment()`, `published()`, `draft()`, `open()`, `upcoming()`, `closed()`, `sharedCode()`, `roster()`, `released()`, `withLatePenalty(type, value, cap)` |
| `Participant`    | `withCode()`, `withOverrides([...])`                                                                                                                                  |
| `Attempt`        | `inProgress()`, `submitted()`, `grading()`, `graded()`, `expired()`                                                                                                   |
| `Answer`         | `pending()`, `needsReview(aiScore, confidence)`, `final(score)`, `failed()`                                                                                           |
| `AssignmentRule` | `automated(check, config)`, `ai()`                                                                                                                                    |
| `Submission`     | `late(minutes)`, `graded()`, `needsReview()`, `notCurrent()`                                                                                                          |
| `AiRun`          | `purpose(…)`                                                                                                                                                          |

## Helpers (`tests/Pest.php` / `tests/Support`)

- `instructorInCourse(?Team $team = null): array{User, Team}` creates a verified user who is a member of a course, sets it as their current team, and logs them in (`actingAs`).
- `otherCourse(): Team` returns a second course with its own member, for isolation tests.
- `studentSession(Participant $p)` puts `student.participant_id` (and the attempt) into the session, the way `JoinAssessment` does.

## Mandatory: course isolation

Every new instructor-facing resource gets a test showing that an instructor from course A gets **403 or 404** on course B's resource, for index, show, update and delete. Use a dataset of route names so the test stays short.

## External services (no real network, ever)

- **AI:** use the Laravel AI SDK's agent fakes. Run `search-docs` (`packages: ["laravel/ai"]`) for the exact API, e.g. faking an agent class with canned structured responses and asserting what it was prompted with. Assert (a) what went into the prompt (rubric, delimited student answer) and (b) how each outcome is handled: valid, invalid, exception.
- **GitHub:** call `Http::preventStrayRequests()` in the test setup, then `Http::fake([...])` with fixtures from `tests/Fixtures/github/`. Assert files were _not_ fetched for ignored paths with `Http::assertNotSent(fn ($r) => str_contains($r->url(), '/vendor/'))`.
- **Mail:** `Mail::fake()` / `Notification::fake()` for invitations.

## Queues and time

- `Queue::fake()` or `Bus::fake()` to assert dispatches (`Queue::assertPushedOn('ai', GradeOpenAnswer::class)`), and that duplicates are _not_ dispatched.
- Run a job directly (`(new GradeOpenAnswer($answer))->handle(...)` or `dispatch_sync`) to test what it does. Call `failed()` directly to test the failure path.
- Deadlines: `$this->travelTo(...)` or `$this->freezeTime()`. Never use `sleep`. Build times from `CarbonImmutable` in UTC, and convert to the course timezone only when testing display.
- Scheduled commands: `$this->artisan('attempts:expire')->assertSuccessful()`, after travelling forward in time.

## Frontend

- `npm run types:check` (vue-tsc) must pass. Use Wayfinder-generated route functions so TypeScript catches broken routes.
- There are no Vue unit tests in v1. Behaviour is covered by feature tests on the props, plus the manual check in M11.6.
