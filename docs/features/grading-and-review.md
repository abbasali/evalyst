# Feature — Grading & Review (Quizzes)

Assignment submissions use the same review inbox and release rules. Their grading pipeline is described in `assignments.md`.

## Choice scoring: `App\Grading\ChoiceScorer` (pure)

Let `C` be the set of correct options, `S` the selected options, `m` the marks, `cs = |S ∩ C|`, `ws = |S \ C|`.

| Type / policy                              | Score                                                           |
| ------------------------------------------ | --------------------------------------------------------------- |
| `single_choice`                            | `m` if `S == C` (one option selected and it is correct), else 0 |
| `multiple_choice` + `all_or_nothing`       | `m` if `S == C`, else 0                                         |
| `multiple_choice` + `partial`              | `ws > 0 ? 0 : m × cs/                                           | C   | `   |
| `multiple_choice` + `partial_with_penalty` | `m × max(0, (cs − ws)/                                          | C   | )`  |

- Results are rounded to 2 decimals (half up).
- An unanswered question (`S` empty) scores 0.
- Choice answers become `final` with `published_at = submitted_at` as soon as they are scored.

## On submit: `GradeAttempt` (queued on `default`, idempotent)

1. Choice answers: score them and set them `final`.
2. Open answers that are blank or whitespace-only (text **and** code): score 0, feedback "No answer submitted.", `final`.
3. All other open answers: `grading_status = pending`, dispatch `GradeOpenAnswer` (one job per answer, on the `ai` queue).
4. Attempt status: `graded` if every answer is final, otherwise `grading`.

## `GradeOpenAnswer`

- If the answer isn't `pending`, do nothing (idempotent).
- Call `OpenAnswerGrader` (03-ai.md). Store `ai_score`, `ai_feedback`, `ai_confidence`, `ai_breakdown`, `ai_flags`, and log the `ai_run`.
- Pass the result through `PublishGate` (03-ai.md):
    - **publish:** `score = ai_score`, `feedback = ai_feedback`, `final`, `published_at = now`
    - **review:** `needs_review`. `score` stays null.
- On final failure: `failed` with the error.
- Afterwards, recalculate the attempt: when every answer is final, the attempt becomes `graded` and `score` = the sum.

## Review inbox: `/{course}/review`

This is the single place for everything waiting on an instructor. The sidebar badge shows the count.

- **Items:** answers in `needs_review` or `failed`, plus submissions in `needs_review` or `failed`.
- **Filters:** assessment, question, status (needs review / failed), reason (low confidence, a specific flag, failed).
- **Views:**
    - **By item** (default): a list showing student, assessment, question excerpt, AI score/max, confidence, flags, and age.
    - **By question:** grade one question across all students one after another. Next and previous keep the same question.
- **Detail panel (answers):**
    - Left: the question, model answer, and rubric.
    - Middle: the student's text and code (highlighted).
    - Right: the AI suggested score, confidence, flags, breakdown, and draft feedback.
    - Actions:
        - **Accept & publish:** score = AI score, feedback = AI feedback.
        - **Edit & publish:** a score input (0–max, 0.5 steps) and an editable feedback textarea.
        - **Retry AI** (failed items only).
        - **Skip.**
    - Keyboard shortcuts: `a` accept, `e` edit, `j`/`k` next/previous.
- **Bulk:** select several items, then **Accept & publish selected**. Items without an AI score (failed) are excluded.
- **Regrade:**
    - **One answer:** resets it to `pending` and re-dispatches. If it was already published, a confirmation says "The student may see a different score".
    - **A whole question in a quiz:** regrades every non-blank answer, typically after the instructor has improved the rubric or model answer (these stay editable on locked questions, see D-009).
- **Overriding published grades:** any final answer can be opened from the attempt detail page and edited. Its `graded_by` is set.

## Audit

Every manual change writes an `audit_logs` entry with action `grade.override`, `grade.accept`, `grade.regrade` or `attempt.reset`, and the before/after `score` and `feedback`. The attempt detail page shows the audit trail for each answer.

## Releasing results

| Mode               | Behaviour                                                                                                                                           |
| ------------------ | --------------------------------------------------------------------------------------------------------------------------------------------------- |
| `manual` (default) | A **Release results** button on the quiz results page sets `results_released_at`. **Unrelease** clears it. Both are audit-logged.                   |
| `automatic`        | `results_released_at` is set by the `assessments:auto-release` command once the quiz is closed (`now ≥ closes_at`) and no attempt is `in_progress`. |

Students see an item only when results are released **and** the item has `published_at` set.

**Release results to students** (`release_results`, default on) sits above the mode. When it's off, results are never released: `resultsReleased()` and `autoReleaseDue()` return false whatever the mode or `results_released_at`, releasing is refused with an error toast, turning it off clears any earlier release, both changes are audit-logged, the results page shows "Change this in Settings" instead of the button, and the dashboard doesn't list the assessment as waiting for release. Students get no results link. The quiz's done page says "You've completed this quiz. Your instructor will grade it." and the assignment page says "You've submitted this assignment. Your instructor will grade it." (green success callout). An old results link shows the same message, with no scores, status or timing. Instructors still see every grade, and the all-scores CSV includes them.

## Student results page: `/results/{participant:public_id}` (no session needed)

- Not released yet: "Results are not available yet." Shows the submission time only.
- Released, for each question in the student's order:
    - the question, their answer, and score/max with feedback
    - if `show_answers_after_release` is on: the correct options are highlighted and the explanation is shown
    - unpublished items show **"Under review"** and no score
- **Total score** is shown only when every answer is published. Otherwise: "Some answers are still under review."
- Model answers and rubrics are **never** shown to students.

Related tasks: M07.1, M07.2, M07.3, M07.4, M07.5, M07.6, M08.1, M08.2, M08.3, M08.4, M08.5, M08.6
