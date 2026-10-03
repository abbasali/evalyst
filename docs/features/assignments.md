# Feature — Assignments (GitHub Projects)

## Purpose

A project students build at home. Each student submits a public GitHub repo URL before the deadline. Grading combines **automated checks** and **AI-judged rules**, then applies the late policy. The instructor can override the late policy for any student at any time. Assignments are `assessments` with `type = assignment` (D-008). How repos are read is in `04-github-ingestion.md`, and the agents are in `03-ai.md`.

## Settings form

| Field                                             | Rules / default                                                          |
| ------------------------------------------------- | ------------------------------------------------------------------------ |
| Title                                             | required, max 150                                                        |
| Problem statement (`instructions`)                | Markdown, required                                                       |
| Opens at                                          | optional (empty = as soon as it is published)                            |
| Deadline (`closes_at`)                            | required                                                                 |
| Late policy                                       | `not_allowed`, `allowed` (no penalty), `penalty`                         |
| Penalty type                                      | `fixed`, `per_hour`, `per_day` (only for `penalty`)                      |
| Penalty value                                     | marks, > 0 (only for `penalty`)                                          |
| Penalty cap                                       | optional marks, ≥ the penalty value                                      |
| Grace period                                      | minutes, 0–1440, default 0                                               |
| Hard cutoff                                       | optional datetime, after the deadline (only for `allowed` and `penalty`) |
| Allow resubmission                                | bool, default on                                                         |
| Show rules to students                            | bool, default on                                                         |
| Extra ignored paths                               | list of globs, optional                                                  |
| Access mode, release mode, auto-publish threshold | same as quizzes                                                          |

Next to the late policy fields, a live summary in plain English shows what students will see, for example: "Late submissions are accepted until 10 Oct 23:59. 2 marks are deducted for each started day late, up to 10 marks."

## Rule builder (the "Rules" tab)

An ordered list of rules. Each has a title (shown to students), marks (> 0), and a kind. The footer shows the total marks (= the assignment's max score). At least 1 rule is required to publish.

| Automated check          | Config fields                                                                                | Help text                                                                                                                    |
| ------------------------ | -------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------- |
| `min_commits`            | Minimum commits                                                                              | "Non-merge commits. Partial credit is proportional."                                                                         |
| `min_commit_days`        | Minimum distinct days                                                                        | "Distinct days with commits, in the course timezone. Commit dates come from the student's machine, so treat them as a hint." |
| `commit_message_pattern` | Regex (presets: Conventional Commits, "Imperative, at least 10 characters"), minimum ratio % | "The share of commit messages that must match."                                                                              |
| `path_exists`            | Glob, minimum matches (default 1)                                                            | "Checked against **all** files in the repo, e.g. `tests/Feature/*Test.php`."                                                 |
| `path_absent`            | Glob                                                                                         | "e.g. `vendor/**`, `.env`. Catches committed junk."                                                                          |
| `file_contains`          | Glob, regex                                                                                  | "At least one matching file contains the pattern."                                                                           |

- **AI rule:** title plus a **description** of what the AI should judge, for example "Uses Form Requests for validation in every store/update action", or "Commit messages are meaningful and describe the change".
- Rules can be edited until the first submission is graded. After that, changing rules requires **Regrade all**, with a confirmation.

## Effective deadline & late logic: `App\Grading\LatePenaltyCalculator` (pure)

- `effective_deadline = participant.deadline_override_at ?? closes_at`
- `late_from = effective_deadline + grace_minutes`
- `minutes_late = max(0, ceil((submitted_at − late_from) / 60s))`
- `is_late = minutes_late > 0`

**Can the student submit now?** The first matching rule wins:

1. The assessment isn't published, or `now < opens_at` → **no**.
2. `late_override = block` and `now > late_from` → **no**.
3. `late_override = allow` → **yes**.
4. `now ≤ late_from` → **yes**.
5. Policy is `not_allowed` → **no** ("The deadline has passed").
6. `hard_cutoff_at` is set and `now > hard_cutoff_at` → **no**.
7. Otherwise → **yes**, as a late submission.

**Penalty** (only when `is_late` and the policy is `penalty`; with a `late_override = allow` the policy still decides the penalty unless it is waived):

- `fixed`: `value`
- `per_hour`: `ceil(minutes_late / 60) × value`
- `per_day`: `ceil(minutes_late / 1440) × value`
- Then `min(penalty, cap)` if a cap is set.
- `penalty_waived` → 0. `penalty_override` (when not null) replaces the calculated value.

**Final:** `score = max(0, raw_score − penalty)`.

## Participant overrides (the "Participants" tab → Override dialog)

Fields:

- deadline override (datetime)
- late override (follow policy / always allow / block)
- waive penalty
- fixed penalty override
- note (**required**)

They can be changed at any time, before or after grading. On save (`ApplyParticipantOverride`):

- Write an audit log `participant.override` with before and after values.
- If a current submission exists, recalculate `minutes_late` against the new effective deadline, then the `penalty` and `score`. If the submission is already published, the new score is published immediately, and a toast reminds the instructor that the student may see a change.

## Submission (student)

Page: `/a/{assessment:public_id}`, reached after joining with a code (same join flow as quizzes).

- Shows:
    - the title and problem statement
    - the rules, if visible (titles and marks only, not their config)
    - the deadline in the course timezone plus a countdown. A student with an override sees **their own** deadline.
    - the late policy in plain English
- **Form:** a repo URL field with the hint "Must be a public GitHub repository". `SubmitRepository` validates and records the head SHA (see 04-github-ingestion.md). Errors:
    - invalid URL
    - repo not found or private
    - GitHub unavailable
    - submissions closed (with the reason from the late rules)
- **Resubmission:** allowed if `allow_resubmission` is on **and** the student may submit now (above). The new submission becomes `is_current`, and the previous ones stay as history. If a previous submission is still grading, its result is discarded when it finishes, because it is no longer current.
- **History:** a table of submitted at, short SHA (links to the commit on GitHub), repo, late by, and status. Below it: the results link `/results/{participant.public_id}`.

## Grading pipeline (`GradeSubmission`, on the `ai` queue)

1. `grading` → `RepositoryIngestor` builds the `RepoSnapshot` and stores `manifest`.
2. Run the automated checks and store their `submission_rule_results`.
3. If there are AI rules, run `ProjectGrader` once and store a result per rule (score, reasoning, evidence, confidence).
4. `raw_score` = the sum of rule scores. Apply the penalty, then set `score`, and `feedback` = the AI summary.
5. `PublishGate` (every AI rule's confidence ≥ threshold, no flags) → `final` + `published_at`, otherwise `needs_review`. If there are no AI rules → `final`.
6. Failure (after retries; GitHub rate limits release the job instead) → `failed` with the error.

## Review

Submissions appear in the review inbox (grading-and-review.md). The detail view shows:

- the manifest (included and skipped files), the commit list, and a link to the repo at the SHA
- a card per rule with score/max, reasoning, evidence, and confidence. Each score and reasoning can be edited, which sets `overridden_by`.
- the overall feedback (editable), the penalty breakdown (calculated, waived or overridden), and the final score

Actions: Accept & publish, Save & publish, Regrade (runs the pipeline again on the same SHA), Retry (failed items). Everything is audit-logged.

## Instructor submissions list: `/{course}/assignments/{id}/submissions`

Columns: roll number, name, submitted at, late by, status, raw score, penalty, score, and override indicator. Students who haven't submitted are included in roster mode.

Related tasks: M09.1, M09.2, M09.3, M09.4, M09.5, M09.6, M09.7, M09.8, M10.1, M10.2, M10.3, M10.4, M10.5, M10.6, M10.7
