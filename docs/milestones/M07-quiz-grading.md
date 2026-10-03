# M07 — Quiz Grading

**Goal:** When an attempt is submitted, it is graded without anyone stepping in. Choice questions are scored instantly. Blank open answers get 0. Other open answers are graded by AI in the background, and confident grades are published automatically. The attempt moves `submitted → grading → graded`.

**Depends on:** M04 (AI foundation, `RecordsAiRun`, `openai` limiter), M06 (attempts, answers, `SubmitAttempt`).
**Read first:** [features/grading-and-review.md](../features/grading-and-review.md), [03-ai.md](../03-ai.md), [02-data-model.md](../02-data-model.md) (`answers`, `attempts`), decisions D-005, D-009.

---

### M07.1 — `ChoiceScorer` (all 3 policies) with table-driven tests

**Goal:** One pure class that scores choice answers.

**Build**

- `app/Grading/ChoiceScorer.php`: `score(QuestionType $type, ?ChoiceScoringPolicy $policy, array $correctOptionIds, array $selectedOptionIds, float $marks): float`. No database access.
- Rules (`C` = correct options, `S` = selected options, `cs = |S ∩ C|`, `ws = |S \ C|`):
    - `single_choice`: full marks if `S == C` (exactly one selected and it's the correct one), otherwise 0.
    - `multiple_choice` + `all_or_nothing`: full marks if the sets are equal, otherwise 0.
    - `partial`: `marks × cs/|C|`, but **0 if `ws > 0`**.
    - `partial_with_penalty`: `marks × max(0, (cs − ws)/|C|)`.
    - If nothing is selected (`S` empty or null), the score is 0.
    - Round to 2 decimal places (half up).
- Duplicate IDs in `S` are removed first. Option IDs that don't belong to the question count as wrong selections.

**Acceptance criteria**

- Every rule above holds, including the edge cases: all selected, none selected, an unknown ID, a single correct option under `partial`.
- The result is always within `[0, marks]`.

**Tests:** `tests/Unit/Grading/ChoiceScorerTest.php`, using a Pest `dataset` with at least 20 rows that cover each policy, plus fractional marks (e.g. 1.5 with 3 correct options gives 0.5 per correct option).

---

### M07.2 — `GradeAttempt` orchestration on submit

**Goal:** Start grading for an attempt and work out its overall status as grades come in.

**Build**

- `app/Actions/Grading/GradeAttempt.php`, called from `SubmitAttempt` (M06.7) in an `afterCommit` callback. For each answer:
    - **choice** → `ChoiceScorer`. Set `score`, `grading_status = final`, `published_at = now()`.
    - **open, blank** (both text and code are empty after `trim`) → `score = 0`, `feedback = "No answer submitted."`, `final`, published. **No AI call.**
    - **open, non-blank** → `grading_status = pending`, then dispatch `GradeOpenAnswer` (M07.4).
- When there are no pending answers, the attempt goes to `graded`. Otherwise it goes to `grading`.
- `app/Actions/Grading/RefreshAttemptScore.php`: recalculates `attempts.score` as the sum of answer scores. It sets the status to `graded` only when **every** answer is `final`. It runs after any answer changes (job, review, regrade). Running it twice must give the same result.
- Choice questions that are present but were never answered still get an answer row (created at start or submit). They score 0.

**Acceptance criteria**

- An attempt with only choice questions is `graded` as soon as it is submitted, with the correct total.
- An attempt with open answers is `grading`, and exactly one job is dispatched per non-blank open answer (checked with `Queue::fake`).
- Calling `GradeAttempt` twice dispatches no duplicate jobs, because it skips answers that aren't `ungraded`.

**Tests:** `tests/Feature/Grading/GradeAttemptTest.php` (Queue::fake, factories for a mixed quiz).

---

### M07.3 — `OpenAnswerGrader` agent + prompt (text and code) + injection hardening

**Goal:** A structured-output agent that grades one `open_text` or `open_code` answer.

**Build**

- `php artisan make:agent OpenAnswerGrader --structured`, in `app/Ai/Agents/OpenAnswerGrader.php`. It implements `Agent` and `HasStructuredOutput`. The provider and model come from config (D-005), with `#[Timeout(180)]`.
- Prompt: `resources/prompts/open-answer-grader.md`, with `{{placeholders}}` for the question, model answer, rubric, max marks and code language. The student's text and code go in `<student_answer>` / `<student_code>` blocks, and the instructions say their content is untrusted.
- Schema exactly as in 03-ai.md: `score`, `feedback`, `breakdown[]`, `confidence`, `flags[]`.
- `app/Ai/Validation/OpenAnswerResultValidator.php`: the score is within `[0, max]` and in 0.5 steps, `confidence` is within `[0, 1]`, required fields are present, and unknown flags are dropped. If validation fails, throw `InvalidAiOutput`.

**Acceptance criteria**

- With the SDK fake, the prompt contains the rubric, the max marks and the delimited student answer.
- The code variant includes the language and the `<student_code>` block. The text variant leaves it out.
- An invalid fake output (score > max) causes `InvalidAiOutput`.

**Tests:** `tests/Feature/Ai/OpenAnswerGraderTest.php`, using SDK agent fakes (check the faking API with `search-docs`).

---

### M07.4 — `GradeOpenAnswer` job

**Goal:** Reliable, rate-limited background grading of one answer.

**Build**

- `app/Jobs/GradeOpenAnswer.php`: `$queue = 'ai'`, `tries = 3`, `backoff = [30, 120, 300]`, `timeout = 180`, middleware `[new RateLimited('openai'), (new WithoutOverlapping($answerId))]`.
- `handle()`:
    1. If the answer isn't `pending`, stop (keeps it idempotent).
    2. Call the agent through `RecordsAiRun` (purpose `open_answer_grading`, subject = answer).
    3. Validate the output.
    4. Store `ai_score`, `ai_feedback`, `ai_confidence`, `ai_breakdown`, `ai_flags`.
    5. Call `PublishGate` (M07.5).
    6. Call `RefreshAttemptScore`.
- `failed()`: set the answer to `failed`, store the error in `ai_flags` or a dedicated field (log it in an `ai_run` with `succeeded = false`), then `RefreshAttemptScore`. The attempt stays `grading`.
- `InvalidAiOutput` must not be retried: call `$this->fail($e)` straight away.

**Acceptance criteria**

- A successful fake → the answer is `final` or `needs_review`, and an `ai_run` is recorded with tokens and cost.
- A thrown exception on the last try → the answer is `failed`.
- A job for an already-`final` answer does nothing.

**Tests:** `tests/Feature/Jobs/GradeOpenAnswerTest.php`.

---

### M07.5 — `PublishGate` + auto-publish wiring

**Goal:** Decide whether an AI grade goes out without review or waits for the instructor.

**Build**

- `app/Grading/PublishGate.php` (pure): `decide(AiGradeResult $r, float $maxScore, float $threshold): GateDecision{publish: bool, reasons: string[]}`. It publishes only if **all** of these hold (03-ai.md):
    1. the output is valid
    2. `confidence ≥ threshold`
    3. there are no flags, except `blank_or_minimal` with a score of 0
    4. for projects: the confidence of every rule ≥ threshold (overload or a separate `decideProject()`)
- The threshold is `assessment.auto_publish_threshold ?? config('evalyst.ai.auto_publish_threshold')`.
- `app/Actions/Grading/ApplyAiGrade.php`:
    - **publish** → `score = ai_score`, `feedback = ai_feedback`, `final`, `published_at = now()`
    - **review** → `needs_review`. `score` stays null and the gate's reasons are stored in `answers.review_reasons`.

**Acceptance criteria:** the dataset covers each rule, including confidence exactly at the threshold (publishes), one flag (review), and the blank exception.

**Tests:** `tests/Unit/Grading/PublishGateTest.php` (dataset) and a feature test that wires the job to the gate.

---

### M07.6 — `grading:recover` scheduled command

**Goal:** Pick up grading work that got stuck after a crash, deploy or lost job.

**Build**

- `app/Console/Commands/RecoverGrading.php` (`grading:recover`). It re-dispatches:
    - `GradeOpenAnswer` for answers that have been `pending` with `updated_at` more than 15 minutes ago
    - `GradeSubmission` for submissions in `submitted`/`grading` for more than 15 minutes (only once M10 exists, guarded by `class_exists` or added in M10.5)
- It touches `updated_at` before dispatching so that two runs in a row don't dispatch the same item twice.
- Register it in `routes/console.php`: `->everyTenMinutes()->withoutOverlapping()->onOneServer()`.

**Acceptance criteria**

- A 20-minute-old pending answer gets a job (Queue::fake).
- A 5-minute-old one doesn't.
- A second immediate run dispatches nothing new.

**Tests:** `tests/Feature/Console/RecoverGradingTest.php`, using `$this->travel()`.
