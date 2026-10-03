# Feature — Question Bank

## Purpose

A course-wide, reusable store of questions. Every instructor in the course sees and edits the same bank. Questions are added to quizzes from here.

## Question types and validation

| Type              | Required fields                                                | Options rules                                                                             |
| ----------------- | -------------------------------------------------------------- | ----------------------------------------------------------------------------------------- |
| `single_choice`   | body, default_marks                                            | 2–8 options, **exactly 1** correct, option bodies required and unique within the question |
| `multiple_choice` | body, default_marks, scoring_policy (default `all_or_nothing`) | 3–8 options, **2 or more** correct, at least 1 incorrect                                  |
| `open_text`       | body, default_marks, model_answer                              | no options                                                                                |
| `open_code`       | body, default_marks, model_answer, code_language               | no options                                                                                |

Common fields:

- body: Markdown, max 20,000 characters
- default_marks: 0.5–100, in steps of 0.5
- rubric: optional (strongly recommended for open types), max 10,000
- explanation: optional, max 10,000
- difficulty: optional
- tags: 0–10

Changing the type in the edit form clears fields that don't apply to the new type (with a confirmation).

## Markdown

- Question bodies, options, model answers, rubrics and explanations are Markdown with fenced code blocks (` ```php `).
- They are rendered everywhere through one `<Markdown>` component: markdown-it, then DOMPurify, then highlight.js. Raw HTML in Markdown is disabled.
- The editor is a textarea with a live preview tab. A CodeMirror Markdown editor is optional later.

## Screens

### Index: `/{course}/questions`

- **Filters:** type, tag (multi), difficulty, source (manual/ai), "needs verification" toggle, "show deleted" toggle. Text search over the body.
- **Columns:** body excerpt (first line, Markdown stripped, 120 characters), type badge, tags, marks, difficulty, source badge, a ⚠ badge if `needs_verification`, a 🔒 badge if locked, and the number of assessments using it.
- **Row actions:** preview, edit (or duplicate when locked), duplicate, delete.
- **Bulk actions:** add tag, remove tag, delete.
- Filters live in query parameters so the URL can be bookmarked. 25 per page.

### Create/edit: `/{course}/questions/create`, `/{id}/edit`

- **Layout:** type selector (segmented control), body editor, then a type-specific section:
    - **Options:** add, remove, reorder; mark correct with a radio for single choice or checkboxes for multiple choice.
    - **Model answer + code language** for open types.
- Then: marks, scoring policy (multiple choice only, with help text showing the formulas from grading-and-review.md), rubric, explanation, difficulty, tags (combobox that can create a new tag inline).
- **"Clear verification flag":** a checkbox, shown only when `needs_verification`.
- Validation errors appear inline. On save, return to the index with a toast.

### Preview (modal)

Renders the question exactly as the student will see it (the same component as quiz-taking), with the correct answers highlighted underneath and the model answer and rubric collapsed.

## Tags

- Unique name per course, max 40 characters. They can be created inline from the question form.
- **Manage tags dialog:** rename, delete (detaches from questions), and the count of questions per tag.

## Locking & duplication (D-009)

- `locked_at` is set when the **first answer is recorded** against the question, by any attempt.
- On a locked question, the **student-facing** fields are read-only: type, body, options (text and which are correct), and code language. The **grading-side** fields stay editable: model answer, rubric, explanation, default marks, difficulty, tags and `needs_verification` (D-009).
- Saving a change to the model answer or rubric on a locked question asks "Regrade affected answers?". The prompt shows how many answers are affected. If confirmed, it queues a regrade (see grading-and-review.md) and writes a `question.rubric_update` audit log.
- **Duplicate** creates a new unlocked copy (options and tags included, `source` copied, body unchanged) and opens it in the editor.
- To change wording or options after a question has been answered, duplicate it. Students who already answered keep being graded against the original.

## Deleting

- Soft delete. A deleted question disappears from the quiz question picker and the index (unless "show deleted" is on). Assessments that already include it keep it and show it normally.
- Restore is available from the "show deleted" view. Permanent deletion is not offered.

## Permissions

Any course member can do any of this (D-003). Every route is course-scoped. Asking for another course's question returns 404.

Related tasks: M03.1, M03.2, M03.3, M03.4, M03.5, M03.6, M03.7
