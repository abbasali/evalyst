# Feature — AI Question Generation

## Purpose

The instructor describes a topic, for example "PHP variables, data types and control structures", and asks for a number of questions of each type. AI generates drafts, a verifier double-checks the answer keys, and the instructor picks which drafts go into the question bank. Agent details are in `03-ai.md`.

## Generation form: `/{course}/questions/generate`

| Field                                           | Rules                                                                                                                                     |
| ----------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------- |
| Prompt                                          | required, 10–1000 characters. Placeholder: "e.g. PHP arrays and array functions for beginners; focus on array_map, array_filter, sorting" |
| Single choice count                             | integer 0–15                                                                                                                              |
| Multiple choice count                           | integer 0–15                                                                                                                              |
| Open text count                                 | integer 0–15                                                                                                                              |
| Open code count                                 | integer 0–15                                                                                                                              |
| Total                                           | 1–30 (`config('evalyst.ai.max_generation_questions')`), shown live                                                                        |
| Difficulty                                      | `easy`, `medium`, `hard`, `mixed` (default `mixed`)                                                                                       |
| Include "what does this code output?" questions | toggle, default on. It only affects choice questions.                                                                                     |
| Tags                                            | optional. Pre-applied to every accepted question, and also used to collect dedupe context.                                                |

Submit creates a `question_generations` row (`pending`), dispatches `GenerateQuestions` on the `ai` queue, and redirects to the generation's page.

Rate limit: at most 3 generations running at once per course. A fourth submission shows the error "Please wait for running generations to finish."

## Job: `GenerateQuestions`

1. Set status to `running`.
2. **Dedupe context:** up to 40 existing questions that share any selected tag (or the 40 most recent if there are no tags), sent as one-line summaries so the model avoids repeating them.
3. Call `QuestionGenerator`. Validate counts per type and the option rules (same rules as question-bank.md). If the counts are wrong, retry once. Otherwise keep the valid questions and record a warning in `drafts`.
4. Call `QuestionVerifier` once for all choice drafts, **without the answer key**. Mark a draft `verification.status = disputed` when the verifier's selection differs from the key or its confidence is below 0.7, and store its reasoning. Otherwise mark it `agreed`. Open questions get `not_applicable`.
5. Store `drafts`, set status `completed`, and log both runs in `ai_runs`.
6. On final failure: status `failed` with the error message.

Each draft in `drafts` has the agent output fields plus `index`, `verification {status, verifier_selected, reasoning}`, and `accepted` (bool).

## Draft review page: `/{course}/questions/generations/{id}`

- While the status is `pending` or `running`: a spinner, the requested counts, and `usePoll` every 3s. When it is `failed`: the error and a **Retry** button, which re-dispatches the job with the same inputs.
- When it is `completed`, show a list of draft cards. Each card shows:
    - a checkbox (selected by default unless disputed), the type badge, difficulty, and suggested marks
    - the body rendered as Markdown, the options with the correct ones marked, the explanation, and the model answer and rubric (collapsed)
    - for disputed drafts, an amber badge: **"Answer key disputed: verifier chose B: <reasoning>"**
    - an **Edit** button that opens the same question form inline as a drawer, using draft data. Edits are saved back into `drafts[i]`, and the verification status becomes `edited`.
- Toolbar: select all, select none, "select only agreed", and the selected count.
- **"Add N selected to bank"** creates questions in one transaction:
    - `source = ai`, `question_generation_id` set
    - `needs_verification = true` if the draft is still `disputed`
    - the generation's tags applied
    - `default_marks = suggested_marks`

    The accepted drafts are marked `accepted = true` and can't be added twice, and `accepted_count` increases. Afterwards, show a toast and a link to the bank filtered by this generation.

- Drafts that aren't selected stay in the generation, so they can be added later.

## History: `/{course}/questions/generations`

A table with: created at, instructor, prompt excerpt, requested total, status, accepted/total, and cost (sum of its `ai_runs`). Failed rows offer Retry.

## Edge cases

- The model returns fewer questions than asked: show the ones it produced plus a banner "Only N of M questions could be generated."
- Code-output questions are always run through the verifier. The instructor is told in the UI that disputed ones should be checked by running the code.
- Draft Markdown is rendered through the same sanitising component, so model output can never inject HTML.

Related tasks: M04.1, M04.2, M04.3, M04.4, M04.5, M04.6, M04.7
