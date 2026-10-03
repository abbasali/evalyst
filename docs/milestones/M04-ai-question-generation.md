# M04 — AI foundation & question generation

**Goal:** set up the shared AI plumbing (SDK, cost logging, rate limiting, test fakes). On top of it, an instructor writes a prompt and the number of questions they want of each type, reviews AI-generated drafts (with answer keys checked by a second AI pass), and adds the ones they choose to the bank.

**Depends on:** M03.
**Read:** `03-ai.md` (all of it), `features/ai-question-generation.md`, `decisions.md` D-005, D-006, D-010.

---

### M04.1 — Install `laravel/ai`, OpenAI config, `ai_runs`, `RecordsAiRun`, rate limiter, fakes

**Build**

- `composer require laravel/ai`, publish the config (`vendor:publish --provider="Laravel\Ai\AiServiceProvider"`), and run its migrations if it ships any. **Use Boost `search-docs` with `packages: ["laravel/ai"]` first.** The SDK is new, so confirm how agents are made, the schema API, `usage`, and how to fake agents in tests.
- Confirm the exact OpenAI model ID (the `gpt-5.4-mini` family) and its pricing, then update `config/evalyst.php` `ai.pricing`. Record the confirmed ID in `decisions.md`.
- `AiRunPurpose` enum. `ai_runs` migration and model (`BelongsToCourse`, morph `subject`).
- `App\Ai\RecordsAiRun`, a service with `run(AiRunPurpose $purpose, Model $subject, Closure $call): mixed`. It times the call, reads `$response->usage`, works out `cost_usd` from config, writes a row whether the call succeeds or fails, and rethrows on failure.
- `AppServiceProvider`: `RateLimiter::for('openai', fn () => Limit::perMinute(config('evalyst.ai.rate_limit_per_minute')))`.
- `App\Ai\Agents\Concerns\UsesConfiguredModel`: a trait or base class that supplies the provider and model from config, so no agent hard-codes them.
- Test helpers in `tests/Pest.php` (`fakeAgent(class, response array)`) wrapping the SDK's fake API.

**Acceptance criteria**

- No agent can be called without an `ai_runs` row being written. The cost maths is correct. An unknown model logs cost 0 and a warning.

**Tests**

- Unit: cost calculation. Feature: `RecordsAiRun` writes a row on success and on an exception (using a faked agent).

### M04.2 — `QuestionGenerator` agent + prompt + schema + validator

**Build**

- `php artisan make:agent QuestionGenerator --structured`, which goes in `app/Ai/Agents`. The schema and instructions are exactly as in `03-ai.md` §1.
- Instructions live in `resources/prompts/question-generator.md`, with `{{placeholders}}`: course name, difficulty, counts per type, the include-code-output flag, and summaries of existing questions.
- `App\Ai\Validation\GeneratedQuestionValidator` (pure) takes the raw output and returns valid drafts plus warnings. It checks:
    - the type is known
    - choice rules (single choice: exactly 1 correct, multiple choice: at least 2 correct, 2–8 options)
    - open types have a `model_answer`, and `open_code` has a `code_language` from `CodeLanguage`
    - `suggested_marks` is clamped to 0.5–100
- Duplicate-avoidance context: up to 40 existing questions in the course that share any of the selected tags (or the newest 40 if no tags are selected). Each is summarised as its first ~100 characters of plain text.

**Acceptance criteria**

- The validator drops invalid drafts with clear warnings and reports when counts per type don't match.

**Tests**

- Dataset-driven unit tests for the validator. A prompt-building test asserting the placeholders are filled in.

### M04.3 — `QuestionVerifier` agent + key comparison

**Build**

- The `QuestionVerifier` structured agent (`03-ai.md` §2), with its prompt in `resources/prompts/question-verifier.md`. It receives **only the choice drafts, with the answer keys removed**, numbered by index.
- `App\Ai\Validation\VerificationComparator` (pure). For each draft it returns `verification = {status: verified|disputed|skipped, verifier_selected: [...], confidence, reasoning}`. The draft is `disputed` when the sets differ or confidence < 0.7. Open questions get `skipped`.

**Acceptance criteria**

- The answer keys never appear in the verifier prompt (assert this in a test). Disputed drafts are correctly identified.

**Tests**

- Comparator datasets. A prompt test that `is_correct` does not leak.

### M04.4 — `question_generations` table + `GenerateQuestions` job

**Build**

- `GenerationStatus` enum, `question_generations` migration/model (`BelongsToCourse`, json casts), and the `questions.question_generation_id` FK migration.
- `App\Jobs\GenerateQuestions` (queue `ai`, `RateLimited('openai')`, `tries=3`, backoff `[30,120,300]`, `timeout=180`):
    1. Mark the generation `running`.
    2. Prompt the generator inside `RecordsAiRun`, then validate. If counts don't match, retry the AI call once, asking only for the missing counts, and merge the results.
    3. Run the verifier on the choice drafts (also inside `RecordsAiRun`) and attach the results.
    4. Store `drafts` (each one gets a stable `uid`) and set `completed`.
    - `failed()` sets the status to `failed` and stores the error.
    - Idempotent: if the generation is already `completed`, return early.

**Acceptance criteria**

- A full fake run stores drafts with verification blocks and 2 `ai_runs` rows (3 when the retry happens). A failure leaves a `failed` status and the error message.

**Tests**

- Job tests with faked agents: happy path, partial counts that trigger the retry, the verifier disputing an answer, the final failure.

### M04.5 — Generation form UI

**Build**

- Routes: `GET questions/generate` (form + history), `POST question-generations` (`StoreQuestionGenerationRequest`).
- Validation:
    - `prompt` required, at most 1000 characters
    - each type count is an integer from 0 to 15
    - total is 1–30 (`config('evalyst.ai.max_generation_questions')`)
    - `difficulty` is one of easy/medium/hard/mixed
    - `include_code_output` is a boolean
    - `tag_ids` must belong to the course
- Throttle: at most 5 generations per instructor per 10 minutes.
- `pages/questions/Generate.vue`: the prompt textarea, four number inputs (Single choice, Multiple choice, Open text, Open code) with a running total, a difficulty select, the code-output toggle, and a tags multi-select. Submitting redirects to the generation page.
- Enable the "Generate with AI" button on the questions index.

**Acceptance criteria**

- Every validation rule is enforced. Submitting creates a `pending` generation and dispatches the job (`Queue::fake` assertion).

**Tests**

- Validation dataset, dispatch, throttle, isolation.

### M04.6 — Draft review UI + "Add selected to bank"

**Build**

- `GET question-generations/{generation}` → `pages/questions/GenerationShow.vue`:
    - While the status is `pending` or `running`: a skeleton with "Generating… ~30s", polled with Inertia v3 `usePoll(3000)`. Polling stops once the status is final.
    - When `completed`: a list of draft cards built from `QuestionView` in preview mode, with the key shown. Each card has a checkbox (selected by default **unless disputed**). A disputed card shows a red warning box with the verifier's answer and reasoning. Each card has an inline **Edit** that opens the same field set as `Form.vue` in a dialog and saves the change into the draft's client-side state.
- `POST question-generations/{generation}/accept` with `drafts: [{uid, ...edited fields}]` → the `AcceptGeneratedQuestions` action:
    - validates each draft with the same rules as `StoreQuestionRequest`
    - creates questions with `source=ai`, `question_generation_id`, the generation's `tag_ids`, and `needs_verification = (verification.status === 'disputed')`
    - increments `accepted_count`
    - rejects any `uid`s it doesn't recognise
- Redirect to the questions index filtered to `source=ai` with a toast "N questions added".

**Acceptance criteria**

- Only the selected drafts are created, with the edits applied. Accepting the same uid twice is rejected (track accepted uids in the drafts JSON).

**Tests**

- Accept flow, edit applied, disputed draft → `needs_verification`, double-accept guard, isolation.

### M04.7 — Generation history + retry

**Build**

- A history table on `Generate.vue` (the latest 20): prompt excerpt, total requested, status, accepted count, cost (summed from `ai_runs`), and when it was created.
- `POST question-generations/{generation}/retry` for `failed` generations only. It resets the status to `pending` and dispatches again.
- On the questions index, clearing `needs_verification` is a one-click "Mark verified" action (`PATCH questions/{question}/verify`).

**Acceptance criteria**

- Only failed generations can be retried. The cost shown matches the sum of the `ai_runs` rows.

**Tests**

- Retry guard and dispatch, mark verified, cost aggregation.
