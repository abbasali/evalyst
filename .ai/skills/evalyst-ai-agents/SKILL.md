---
name: evalyst-ai-agents
description: Build or change Evalyst AI features — Laravel AI SDK agents (QuestionGenerator, QuestionVerifier, OpenAnswerGrader, ProjectGrader), AI jobs, prompt files, PublishGate, ai_runs cost logging, and their tests. Use whenever touching app/Ai, resources/prompts, AI-related jobs, or app/Grading/PublishGate.
---

# Evalyst AI Agents

## Read first

- `docs/03-ai.md` covers agent contracts, output schemas, PublishGate rules, cost and rate limits. It is the source of truth.
- `docs/decisions.md` D-005 (one model from config) and D-006 (no Jev).
- Use Boost `search-docs` with `packages: ["laravel/ai"]` to check SDK APIs (agent attributes, `schema()`, response `usage`, testing fakes) before you write code. The SDK is new, so don't rely on memory.

## Shape of every agent

- Create it with `php artisan make:agent <Name> --structured` under `app/Ai/Agents`.
- Implement `Agent` + `HasStructuredOutput` and use `Promptable`.
- Load `instructions()` from `resources/prompts/<kebab-name>.md`, using `{{placeholder}}` replacement (not Blade).
- Take the provider and model from `config('evalyst.ai.provider')` / `config('evalyst.ai.model')`. Never hard-code them.
- The `schema()` method must match the JSON in `docs/03-ai.md` exactly. Mark every field `required()`.
- Put student content inside tagged blocks (`<student_answer>`, `<student_code>`, `<file path="…">`). The instructions must say this content is untrusted and that the model must report attempts in `flags`.

## Shape of every AI job

- Runs on `config('evalyst.ai.queue')` (default `ai`) with `RateLimited('openai')` middleware, `tries = 3`, `backoff = [30, 120, 300]`, and a timeout from 03-ai.md.
- Idempotent: return early if the subject is no longer `pending` / `grading`.
- Wrap the prompt call in `RecordsAiRun`, which writes an `ai_runs` row (purpose, subject, model, tokens, `cost_usd`, `duration_ms`, `succeeded`, error) on success **and** on failure.
- Validate the output before using it. A score outside `[0, max]`, a missing rule id, or a wrong count means the output is invalid, and the item fails closed (`needs_review` or `failed`).
- Pass the result to `App\Grading\PublishGate`. On `publish`, set the score, feedback and `published_at`, and set the status to `final`. On `review`, set the status to `needs_review` and keep `ai_*` fields for the reviewer.
- `failed()` sets the subject's status to `failed` and stores the error message.

## Tests (required)

- Use SDK agent fakes. Never call the real network.
- Assert that the prompt includes the rubric / model answer / tagged student content, and does **not** include the answer key for `QuestionVerifier`.
- Cover: a confident result is auto-published; low confidence → `needs_review`; any flag → `needs_review`; invalid output → fails closed; blank answer → 0 with no agent call; an `ai_runs` row is written with cost.
- `PublishGate` is pure: table-driven unit tests with Pest datasets.
