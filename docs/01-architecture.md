# 01 — Architecture

## Stack (installed, see D-001)

| Layer         | Choice                                                                                                                                                        |
| ------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Backend       | Laravel 13, PHP 8.5 (Herd locally), MySQL 8                                                                                                                   |
| Auth          | Fortify (login, password reset, 2FA, passkeys). Registration is turned off (D-004).                                                                           |
| Frontend      | Inertia v3 + Vue 3 + TypeScript, Tailwind v4, shadcn-vue style components in `resources/js/components/ui` (reka-ui), `@lucide/vue` icons, `vue-sonner` toasts |
| Routing in JS | Wayfinder (`resources/js/actions`, `resources/js/routes`). Never hard-code URLs in Vue.                                                                       |
| AI            | `laravel/ai` (Laravel AI SDK), provider OpenAI, model from `config('evalyst.ai.model')`                                                                       |
| Queues        | Laravel queues: the `database` driver locally, **Managed Queues** on Laravel Cloud                                                                            |
| Tests         | Pest 5, Larastan level 7, Pint, `vue-tsc`, the starter kit's `npm run check`                                                                                  |
| Hosting       | Laravel Cloud + MySQL + Managed Queues + scheduler                                                                                                            |

### Approved dependencies to add (only in the milestone that needs them)

Boost guidelines forbid adding dependencies without approval. These are approved:

| Package                                                                                                                                                                                                                                                                                                      | Purpose                                                     | Milestone                |
| ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ----------------------------------------------------------- | ------------------------ |
| `laravel/ai`                                                                                                                                                                                                                                                                                                 | AI agents, structured output                                | M03                      |
| `markdown-it` + `@types/markdown-it`                                                                                                                                                                                                                                                                         | Render question and statement Markdown                      | M02                      |
| `dompurify`                                                                                                                                                                                                                                                                                                  | Sanitise rendered Markdown                                  | M02                      |
| `highlight.js` (core + only the `CodeLanguage` languages: bash, c, cpp, csharp, css, go, java, javascript, json, kotlin, php, python, ruby, rust, sql, swift, typescript, xml/html, blade via xml)                                                                                                           | Syntax highlighting                                         | M02                      |
| `codemirror` 6 packages (`@codemirror/state`, `view`, `commands`, `language`, `lang-php`, `lang-javascript`, `lang-sql`, `lang-html`, `lang-css`, `lang-python`, `lang-java`, `lang-cpp`, `lang-go`, `lang-rust`, `legacy-modes` for C#, Kotlin, Swift, Ruby and Bash). Language modes are loaded on demand. | Code editor for `open_code` answers and the question editor | M05 (also usable in M02) |
| `aws/aws-sdk-php`                                                                                                                                                                                                                                                                                            | Required by Laravel Cloud Managed Queues (SQS)              | M12                      |

Anything else needs a decision entry in `decisions.md` and the user's approval.

## Directory conventions

Follow the starter kit's existing structure. New folders allowed:

```
app/
├── Actions/<Domain>/            # Single-purpose classes with handle(), like Actions/Teams/CreateTeam
│   ├── Questions/               # CreateQuestion, UpdateQuestion, DuplicateQuestion, ...
│   ├── Assessments/             # PublishAssessment, JoinAssessment, StartAttempt, SubmitAttempt, ...
│   ├── Grading/                 # ScoreChoiceAnswer, PublishGrade, ApplyGradeOverride, ...
│   └── Assignments/             # SubmitRepository, ApplyParticipantOverride, ...
├── Ai/
│   ├── Agents/                  # QuestionGenerator, QuestionVerifier, OpenAnswerGrader, ProjectGrader
│   └── Prompts/                 # Blade/markdown prompt templates if they grow long (resources/prompts/*.md ok too)
├── Concerns/                    # Model traits, e.g. BelongsToCourse
├── Data/                        # Plain readonly DTOs (existing folder), e.g. RepoSnapshot, GradeResult
├── Enums/                       # Every status/type enum (string-backed)
├── Grading/                     # Pure domain logic: ChoiceScorer, LatePenaltyCalculator, PublishGate
├── Http/Controllers/<Area>/     # Instructor/*, Student/*
├── Http/Requests/<Area>/
├── Jobs/                        # GenerateQuestions, GradeOpenAnswer, GradeSubmission, ...
├── Models/
├── Policies/
└── Services/GitHub/             # GitHubClient, RepositoryIngestor, Checks/*
```

Vue pages: `resources/js/pages/<area>/<Resource>/<Page>.vue`, for example `pages/questions/Index.vue`, `pages/student/quiz/Question.vue`. Reusable pieces go in `resources/js/components/<domain>/`, for example `components/questions/QuestionEditor.vue`, `components/markdown/Markdown.vue`.

## Coding patterns

- **Controllers stay thin.** Validate with a Form Request, authorise with a Policy, call an Action, then return an Inertia response or redirect.
- **Actions** hold the business logic: one public `handle()` method, injected through the constructor, wrapping multi-table writes in `DB::transaction`. Tests can call them directly.
- **Pure domain logic** (scoring, late penalties, the publish decision) goes in `app/Grading/` classes with no I/O, covered by table-driven unit tests.
- **Enums** for every type and status, cast on models. Add a `label()` method when the UI shows the value.
- **Money and scores:** scores are stored as `decimal(8,2)`. AI cost is stored as `decimal(12,6)` USD.
- **Times:** store UTC. Each course has a `timezone`, and the UI shows times in it. Every deadline comparison happens on the server.
- **IDs in public URLs:** student-facing URLs never expose auto-increment IDs. Use a `ulid` column (`public_id`) on `assessments`, `attempts`, `participants` (for the results token), and `submissions`.
- **Never use `env()` outside config files.** App settings live in `config/evalyst.php`.

## Course scoping and authorisation (the most important rule)

- Every course-owned table has `team_id`, which is not nullable, indexed, and has a foreign key with cascade on delete.
- Instructor routes live under the starter kit's `{current_team}` prefix with `EnsureTeamMembership`, and use **scoped route bindings** (`->scopeBindings()`). A model from another course must give a 404.
- A `BelongsToCourse` trait (`app/Concerns`) adds the `team()` relation, a `scopeForCourse(Team $team)` scope, and fills in `team_id` from the current team on create when it is missing.
- Each resource has a Policy that checks `$user->belongsToTeam($model->team)`. All members are equal (D-003).
- Every new resource must have a feature test proving that a member of course A gets 403/404 on a resource from course B.

## Student side

- Students are not authenticated users. After a successful join, the session stores `student.participant_id` (and `student.attempt_id` once a quiz is started).
- The `EnsureStudentSession` middleware loads the participant and checks that it matches the route's attempt or assessment.
- Student routes live in `routes/student.php` with a minimal layout (`layouts/StudentLayout.vue`): no sidebar, the course name, and the timer slot.
- Join attempts are rate-limited per IP (`throttle:join`, 10 per minute).
- Results links use `participants.public_id` (a ULID): `/results/{participant:public_id}`. This link is the student's only "login" for results, so it must never be guessable.

## Queues and jobs

| Queue     | Jobs                                                                                                  | Notes                                                                 |
| --------- | ----------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------- |
| `ai`      | `GenerateQuestions`, `GradeOpenAnswer`, `GradeSubmission` (GitHub ingestion + checks + AI in one job) | `RateLimited('openai')` middleware; `tries=3`, `backoff=[30,120,300]` |
| `default` | Everything else (notifications, exports)                                                              |                                                                       |

- Every job is idempotent: re-running it must not double-count. It checks the current status before doing any work.
- When a job finally fails (`failed()`), it sets the subject's status to `failed` with the error message. Failed items appear in the review inbox with a "Retry" button.
- Scheduled commands (`routes/console.php`):
    - `attempts:expire` every minute: auto-submits attempts past their deadline (plus a 30s grace period).
    - `assessments:auto-release` every minute: sets `results_released_at` on closed `automatic`-release assessments. For quizzes this waits until no attempt is `in_progress`.
    - `grading:recover` every 10 minutes: re-dispatches items stuck in `pending`/`grading` for more than 15 minutes.

## Live updates

There are no websockets (D-010). Pages that wait on background work use Inertia v3 `usePoll`, every 3s while the status is pending and stopping once it finishes:

- question generation
- grading status of an attempt or submission
- the instructor's live quiz monitor (every 5s)

## Config: `config/evalyst.php`

```php
return [
    'ai' => [
        'provider' => env('EVALYST_AI_PROVIDER', 'openai'),
        'model' => env('EVALYST_AI_MODEL', 'gpt-5.4-mini'),
        // USD per 1M tokens, used for ai_runs.cost_usd
        'pricing' => [
            'gpt-5.4-mini' => ['input' => 0.75, 'output' => 4.50],
        ],
        'auto_publish_threshold' => 0.80,   // per-assessment override allowed
        'max_generation_questions' => 30,
        'max_concurrent_generations' => 3,  // per course; queued/running generations
        'rate_limit_per_minute' => 60,      // RateLimiter::for('openai')
    ],
    'student' => [
        'join_attempts_per_minute' => 10,   // RateLimiter::for('join'), per IP
    ],
    'github' => [
        'token' => env('GITHUB_TOKEN'),
        'max_context_tokens' => 100_000,
        'max_file_bytes' => 100_000,
        'max_commits' => 500,
        'ignored_paths' => [               // see 04-github-ingestion.md
            'directories' => [/* segment names, e.g. 'vendor', 'node_modules', 'bootstrap/cache' */],
            'files' => [/* globs, e.g. '*.lock', '*.min.js', '.env.*' */],
            'extensions' => [/* binary/media, e.g. 'png', 'zip', 'pdf' */],
        ],
    ],
    'assignments' => [
        'auto_grade' => (bool) env('EVALYST_ASSIGNMENTS_AUTO_GRADE', false), // temporary guard M09.6 → removed in M10.5
    ],
    'quiz' => [
        'save_grace_seconds' => 30,  // saves accepted after the deadline (network latency)
    ],
];
```

## Tooling commands

- `composer test` runs Pint check, PHPStan and Pest. **It must pass before a task is ticked.** Tests run on MySQL (`evalyst_test`), not SQLite (D-012).
- `composer lint` fixes formatting with Pint.
- `npm run types:check` and `npm run check` must pass for any task that touches the frontend.
- `npm run build` is needed if Vite manifest errors appear. Locally the site is served by Herd at `http://evalyst.test`.
