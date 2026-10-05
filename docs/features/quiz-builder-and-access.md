# Feature — Quiz Builder & Access

## Purpose

Create a timed quiz from bank questions, set when it runs, and choose how students get in. Quizzes are `assessments` with `type = quiz` (D-008).

## Quiz list: `/{course}/quizzes`

- Tabs: Upcoming, Open, Closed, Drafts, Archived. These are worked out from status and dates.
- Columns: title, status badge, opens/closes (course timezone), duration, number of questions, total marks, participants (started/submitted), and count needing review.
- Actions: create, edit, monitor, results, archive.

## Settings form

| Field                                               | Rules / default                                                               |
| --------------------------------------------------- | ----------------------------------------------------------------------------- |
| Title                                               | required, max 150                                                             |
| Instructions                                        | Markdown, optional, shown on the start page                                   |
| Opens at                                            | optional datetime (course timezone). Empty = opens as soon as it is published |
| Closes at                                           | required datetime: the last moment an attempt can **start or continue**       |
| Duration (minutes)                                  | required, 1–600                                                               |
| Shuffle questions / shuffle options                 | bool, default off                                                             |
| Track focus loss                                    | bool, default on                                                              |
| Release results to students                         | bool, default on. Off hides the two rows below                                |
| Release mode                                        | `manual` (default) or `automatic`                                             |
| Show correct answers and explanations after release | bool, default on                                                              |
| Auto-publish threshold                              | optional 0.50–1.00. Empty = config default (0.80). Help text explains it      |
| Access mode                                         | `roster` or `shared_code` (see below)                                         |

Times are entered and displayed in the course timezone and stored in UTC.

## Question picker (edit page, "Questions" tab)

- **List:** the selected questions, in order. Each shows position, excerpt (click to preview), type, editable **marks** (defaults to `default_marks`, 0.5 steps), a remove button, and up/down reorder buttons. The footer shows the question count and total marks.
- **"Add questions" side sheet:** a bank browser with the same filters as the question bank (type, tag, difficulty, search), multi-select across pages with "select all on this page". Questions already added are disabled. Soft-deleted questions don't appear. A ⚠ badge appears on questions with `needs_verification`, and adding one shows a warning and an "Add anyway" button.
- A question can appear only once per quiz.

## Access modes

### Roster (`roster`)

- The "Participants" tab lists the course roster with checkboxes and "select all". Saving creates `participants` for the selected students, each with a 6-character `access_code` (alphabet `ACDEFHJKMNPRTWXY3479` (no look-alikes, D-020), unique across the whole table, retried on collision).
- A student can be added at any time, including while the quiz is open. They can be removed only if they haven't started.
- Per-row action **Regenerate code**: the old code stops working immediately.
- **Codes sheet:** a printable page (one card per student: name, roll number, code, join URL `/join`) and a **CSV download** (roll_number, name, code).

### Shared code (`shared_code`)

- One 6-character code on the assessment (`shared_code`, same alphabet, unique). Students enter it plus their name and roll number.
- **Rotate code:** issues a new code, and the old one stops working for _new_ joins. Students who already joined can still resume (see quiz-taking.md).
- Participants appear on the Participants tab as they join.

The access mode can be changed only while the quiz has no participants (D-019).

## Status lifecycle

`draft` → `published` → `archived`. Open/closed is derived:

- **upcoming:** published and `now < opens_at`
- **open:** published and `opens_at ≤ now < closes_at` (a null `opens_at` counts as already open)
- **closed:** `now ≥ closes_at`

### Publish checks (blocking; shown as a checklist)

- At least 1 question, and every question has marks > 0
- `closes_at` is in the future, and `opens_at < closes_at` when set
- Duration is set
- Roster mode: at least 1 participant. Shared mode: a code exists (generated automatically on publish)

Unpublishing (back to draft) is allowed only while nobody has started.

### Editing once any attempt exists

| Allowed                                                                                                       | Blocked                                                                                                              |
| ------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------- |
| Title, instructions, extending `closes_at`, release mode, show-answers, threshold, adding roster participants | Adding, removing or reordering questions; marks; duration; shuffle settings; access mode; moving `closes_at` earlier |

The UI disables the blocked fields with a tooltip, "Locked because students have started", and the server enforces the same rules.

### Archive

Archived quizzes are hidden from the default tabs and become read-only. Results stay available. Unarchiving returns the quiz to `published`.

## Preview

"Preview as student" opens the real quiz-taking UI in preview mode: a banner "Preview, nothing is saved", a timer that runs, and answers held only in browser memory. No participant, attempt or answer rows are created, and questions are **not** locked.

## Permissions

Any course member. Every route is course-scoped with scoped bindings.

Related tasks: M05.1, M05.2, M05.3, M05.4, M05.5, M05.6, M06.12
