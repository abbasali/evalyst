# 02 — Data Model

This is the source of truth for tables, columns and enums. If a task needs a schema change that isn't described here, update this file in the same task and add an entry to `decisions.md`.

Every table also has `id` and `timestamps()` unless stated otherwise. **(C)** marks a course-owned table: it has a `team_id` foreign key to `teams`, cascades on delete, and is indexed.

## Overview

```
teams (Course) ─┬─< team_members >── users (Instructor)
                ├─< team_invitations
                ├─< students
                ├─< tags >──< question_tag >──┐
                ├─< questions ─< question_options
                ├─< question_generations
                ├─< ai_runs
                ├─< audit_logs
                └─< assessments ─┬─< assessment_questions >── questions
                                 ├─< assignment_rules
                                 └─< participants >── students
                                        ├─< attempts ─< answers >── assessment_questions
                                        │      └─< attempt_events
                                        └─< submissions ─< submission_rule_results >── assignment_rules
```

## Existing tables from the starter kit (leave them alone except where noted)

- `users`, `teams`, `team_members` (role), `team_invitations`, `passkeys`, cache, jobs, sessions.
- **Add to `teams`:**
    - `description` text, nullable
    - `timezone` string, default `'Asia/Kolkata'`

    These are set in the course settings screen.

## Enums (`app/Enums`, string-backed)

| Enum                  | Cases                                                                                                                                                                          |
| --------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `QuestionType`        | `single_choice`, `multiple_choice`, `open_text`, `open_code`                                                                                                                   |
| `ChoiceScoringPolicy` | `all_or_nothing`, `partial`, `partial_with_penalty`                                                                                                                            |
| `Difficulty`          | `easy`, `medium`, `hard`                                                                                                                                                       |
| `QuestionSource`      | `manual`, `ai`                                                                                                                                                                 |
| `CodeLanguage`        | `bash`, `blade`, `c`, `csharp`, `cpp`, `css`, `go`, `html`, `java`, `javascript`, `json`, `kotlin`, `php`, `python`, `ruby`, `rust`, `sql`, `swift`, `typescript`, `plaintext` |
| `GenerationStatus`    | `pending`, `running`, `completed`, `failed`                                                                                                                                    |
| `AssessmentType`      | `quiz`, `assignment`                                                                                                                                                           |
| `AssessmentStatus`    | `draft`, `published`, `archived` (whether it is open/closed is worked out from dates, see below)                                                                               |
| `AccessMode`          | `roster`, `shared_code`                                                                                                                                                        |
| `ReleaseMode`         | `manual`, `automatic`                                                                                                                                                          |
| `AttemptStatus`       | `in_progress`, `submitted`, `grading`, `graded`                                                                                                                                |
| `AnswerGradingStatus` | `ungraded` (not submitted yet), `pending` (queued for AI), `needs_review`, `failed`, `final` (score settled + published)                                                       |
| `LatePolicy`          | `not_allowed`, `allowed`, `penalty`                                                                                                                                            |
| `PenaltyType`         | `fixed`, `per_hour`, `per_day`                                                                                                                                                 |
| `LateOverride`        | `allow`, `block` (nullable column = follow the assessment's policy)                                                                                                            |
| `SubmissionStatus`    | `submitted`, `grading`, `needs_review`, `failed`, `final` (score settled + published)                                                                                          |
| `RuleKind`            | `automated`, `ai`                                                                                                                                                              |
| `AutomatedCheck`      | `min_commits`, `min_commit_days`, `commit_message_pattern`, `path_exists`, `path_absent`, `file_contains`                                                                      |
| `AiRunPurpose`        | `question_generation`, `question_verification`, `open_answer_grading`, `project_grading`                                                                                       |
| `AttemptEventType`    | `focus_lost`, `focus_returned`, `pasted`, `fullscreen_exited`, `resumed`, `auto_submitted`, `force_submitted`, `resume_allowed`                                                |

## Tables

### `students` (C)

| Column      | Type             | Notes                        |
| ----------- | ---------------- | ---------------------------- |
| team_id     | FK               |                              |
| name        | string           |                              |
| roll_number | string(50)       | unique with `team_id`        |
| email       | string, nullable | optional, not used for login |

### `tags` (C) and `question_tag`

- `tags`: `team_id`, `name` (unique with `team_id`)
- `question_tag`: `question_id`, `tag_id` (composite primary key)

### `questions` (C)

| Column                 | Type                            | Notes                                                                                              |
| ---------------------- | ------------------------------- | -------------------------------------------------------------------------------------------------- |
| team_id                | FK                              |                                                                                                    |
| type                   | `QuestionType`                  |                                                                                                    |
| body                   | longText                        | Markdown (code fences allowed)                                                                     |
| code_language          | `CodeLanguage`, nullable        | Required for `open_code`: the language of the answer editor                                        |
| default_marks          | decimal(8,2)                    | Default 1                                                                                          |
| scoring_policy         | `ChoiceScoringPolicy`, nullable | Only for `multiple_choice`. Default `all_or_nothing`                                               |
| model_answer           | longText, nullable              | Required for `open_text` and `open_code`                                                           |
| rubric                 | longText, nullable              | Grading guidance for the AI and reviewers                                                          |
| explanation            | longText, nullable              | Shown to students after results are released                                                       |
| difficulty             | `Difficulty`, nullable          |                                                                                                    |
| source                 | `QuestionSource`                |                                                                                                    |
| question_generation_id | FK, nullable, null on delete    | If the question came from an AI generation                                                         |
| needs_verification     | bool, default false             | Set when the AI verifier disagreed with the answer key. The instructor clears it.                  |
| created_by             | FK users, nullable              |                                                                                                    |
| locked_at              | timestamp, nullable             | Set the first time an answer is recorded against it. Locks the student-facing fields only (D-009). |
| deleted_at             | soft deletes                    |                                                                                                    |

### `question_options`

| Column      | Type                 | Notes    |
| ----------- | -------------------- | -------- |
| question_id | FK, cascade          |          |
| body        | text                 | Markdown |
| is_correct  | bool                 |          |
| position    | unsignedSmallInteger |          |

### `question_generations` (C)

| Column              | Type                            | Notes                                                                                                                                                                              |
| ------------------- | ------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| team_id, user_id    | FK                              |                                                                                                                                                                                    |
| prompt              | text                            | The instructor's topic prompt                                                                                                                                                      |
| type_counts         | json                            | e.g. `{"single_choice":5,"multiple_choice":3,"open_text":1,"open_code":1}`                                                                                                         |
| difficulty          | string                          | `easy`, `medium`, `hard` or `mixed`                                                                                                                                                |
| include_code_output | bool                            | Ask for "what does this code output?" style questions                                                                                                                              |
| tag_ids             | json                            | Tags pre-applied to accepted questions                                                                                                                                             |
| status              | `GenerationStatus`              |                                                                                                                                                                                    |
| drafts              | json, nullable                  | The array of generated draft questions (format in 03-ai.md). Each draft also has a stable `uid`, a `verification` block and `accepted` (bool), so a draft can't be accepted twice. |
| accepted_count      | unsignedSmallInteger, default 0 |                                                                                                                                                                                    |
| error               | text, nullable                  |                                                                                                                                                                                    |

### `assessments` (C)

| Column                     | Type                            | Notes                                                                                                                                                                                                                                                                                 |
| -------------------------- | ------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| team_id                    | FK                              |                                                                                                                                                                                                                                                                                       |
| public_id                  | ulid, unique                    | Used in student URLs                                                                                                                                                                                                                                                                  |
| type                       | `AssessmentType`                |                                                                                                                                                                                                                                                                                       |
| title                      | string                          |                                                                                                                                                                                                                                                                                       |
| instructions               | longText, nullable              | Markdown shown before starting (quiz) or as the problem statement (assignment)                                                                                                                                                                                                        |
| status                     | `AssessmentStatus`              |                                                                                                                                                                                                                                                                                       |
| access_mode                | `AccessMode`                    |                                                                                                                                                                                                                                                                                       |
| shared_code                | string(12), nullable, unique    | Only for `shared_code` mode. 6 characters, unique across shared and roster codes (D-030).                                                                                                                                                                                             |
| opens_at                   | datetime, nullable              | null = open as soon as it is published                                                                                                                                                                                                                                                |
| closes_at                  | datetime                        | Quiz: the last moment an attempt can start. Assignment: the deadline.                                                                                                                                                                                                                 |
| release_mode               | `ReleaseMode`                   | default `manual`                                                                                                                                                                                                                                                                      |
| release_results            | bool                            | default true. Off: results are never shown to students, and the release controls are hidden (D-037)                                                                                                                                                                                   |
| results_released_at        | datetime, nullable              | In `automatic` mode, set by `assessments:auto-release`: for quizzes once `now ≥ closes_at` and no attempt is still `in_progress` (attempts can run up to `duration_minutes` past `closes_at`), and for assignments once `now ≥ max(closes_at, latest participant deadline override)`. |
| auto_publish_threshold     | decimal(3,2), nullable          | null = config default                                                                                                                                                                                                                                                                 |
| created_by                 | FK users                        |                                                                                                                                                                                                                                                                                       |
| **Quiz-only**              |                                 |                                                                                                                                                                                                                                                                                       |
| duration_minutes           | unsignedSmallInteger, nullable  |                                                                                                                                                                                                                                                                                       |
| shuffle_questions          | bool, default false             |                                                                                                                                                                                                                                                                                       |
| shuffle_options            | bool, default false             |                                                                                                                                                                                                                                                                                       |
| show_answers_after_release | bool, default true              | Show correct options and explanations in the results                                                                                                                                                                                                                                  |
| track_focus                | bool, default true              | Also records pasting into answers (D-021)                                                                                                                                                                                                                                             |
| one_way_navigation         | bool, default false             | Students can't go back to earlier questions (D-021)                                                                                                                                                                                                                                   |
| require_fullscreen         | bool, default false             | The quiz is hidden until the browser is fullscreen; exits are recorded (D-021)                                                                                                                                                                                                        |
| **Assignment-only**        |                                 |                                                                                                                                                                                                                                                                                       |
| late_policy                | `LatePolicy`, nullable          |                                                                                                                                                                                                                                                                                       |
| penalty_type               | `PenaltyType`, nullable         |                                                                                                                                                                                                                                                                                       |
| penalty_value              | decimal(8,2), nullable          | Marks deducted (per unit for per_hour/per_day)                                                                                                                                                                                                                                        |
| penalty_cap                | decimal(8,2), nullable          | Maximum total deduction                                                                                                                                                                                                                                                               |
| grace_minutes              | unsignedSmallInteger, default 0 |                                                                                                                                                                                                                                                                                       |
| hard_cutoff_at             | datetime, nullable              | After this, nobody can submit unless they have a per-student `allow` override                                                                                                                                                                                                         |
| allow_resubmission         | bool, default true              | Before the deadline only, unless overridden                                                                                                                                                                                                                                           |
| show_rules_to_students     | bool, default true              |                                                                                                                                                                                                                                                                                       |
| extra_ignored_paths        | json, nullable                  | Added to the global ignore list                                                                                                                                                                                                                                                       |
| deleted_at                 | soft deletes                    |                                                                                                                                                                                                                                                                                       |

Helper methods on the model: `isOpen()`, `isUpcoming()`, `isClosed()`, `maxScore()`. They are worked out from the status and dates, never stored.

### `assessment_questions`

| Column        | Type                   | Notes                                      |
| ------------- | ---------------------- | ------------------------------------------ |
| assessment_id | FK, cascade            |                                            |
| question_id   | FK, restrict on delete |                                            |
| position      | unsignedSmallInteger   |                                            |
| marks         | decimal(8,2)           | Copied from `default_marks`, can be edited |

Unique: (`assessment_id`, `question_id`).

### `assignment_rules`

| Column        | Type                       | Notes                                                                                     |
| ------------- | -------------------------- | ----------------------------------------------------------------------------------------- |
| assessment_id | FK, cascade                |                                                                                           |
| kind          | `RuleKind`                 |                                                                                           |
| title         | string                     | Shown to students if rules are visible                                                    |
| description   | text, nullable             | The detailed criterion. For AI rules this is what the model judges against.               |
| check         | `AutomatedCheck`, nullable | Required when kind = automated                                                            |
| config        | json, nullable             | Check parameters, e.g. `{"min":15}`, `{"glob":"tests/**/*Test.php"}`, `{"pattern":"^(feat | fix | chore)(\\(.+\\))?: .+","min_ratio":0.8}` |
| marks         | decimal(8,2)               |                                                                                           |
| position      | unsignedSmallInteger       |                                                                                           |

Once any submission for the assessment has been graded, rules **cannot be deleted**. The policy blocks it, and the UI hides the delete button. They can still be edited, followed by "Regrade all". This stops the cascade from silently removing past `submission_rule_results`.

### `participants`

The override columns (`deadline_override_at` … `override_note`) are added in **M09.1**. M05.1 creates the rest.

| Column               | Type                         | Notes                                                                                           |
| -------------------- | ---------------------------- | ----------------------------------------------------------------------------------------------- |
| assessment_id        | FK, cascade                  |                                                                                                 |
| student_id           | FK, cascade                  |                                                                                                 |
| public_id            | ulid, unique                 | Results link token                                                                              |
| access_code          | string(12), nullable, unique | Roster mode only. Alphabet `ACDEFHJKMNPRTWXY3479` (no look-alikes, D-020), 6 characters (D-030) |
| deadline_override_at | datetime, nullable           | Assignment: this student's effective deadline                                                   |
| late_override        | `LateOverride`, nullable     |                                                                                                 |
| penalty_waived       | bool, default false          |                                                                                                 |
| penalty_override     | decimal(8,2), nullable       | A fixed penalty that replaces the calculated one                                                |
| override_note        | text, nullable               |                                                                                                 |
| joined_at            | datetime, nullable           |                                                                                                 |

Unique: (`assessment_id`, `student_id`).

### `attempts`

| Column                | Type                            | Notes                                                                                                                                           |
| --------------------- | ------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------- |
| participant_id        | FK, cascade, **unique**         | One attempt per participant. An instructor "reset" deletes it.                                                                                  |
| public_id             | ulid, unique                    |                                                                                                                                                 |
| status                | `AttemptStatus`                 |                                                                                                                                                 |
| started_at            | datetime                        |                                                                                                                                                 |
| deadline_at           | datetime                        | `started_at + duration` (closing time is the last moment to start, D-031)                                                                       |
| submitted_at          | datetime, nullable              |                                                                                                                                                 |
| auto_submitted        | bool, default false             |                                                                                                                                                 |
| question_order        | json                            | Array of `assessment_question_id`s, fixed at start                                                                                              |
| option_order          | json, nullable                  | `{assessment_question_id: [option_id,…]}`                                                                                                       |
| furthest_position     | unsignedSmallInteger, default 1 | Highest question position reached; enforces one-way navigation (D-021)                                                                          |
| resume_token          | string(64)                      | Hashed. Stored in a cookie when the attempt starts.                                                                                             |
| resume_override_until | datetime, nullable              | Set by the instructor's "allow resume" (M06.8). The first resume inside this window, from any browser, issues a new token and clears the field. |
| score                 | decimal(8,2), nullable          | Sum of answer scores                                                                                                                            |
| max_score             | decimal(8,2)                    | Snapshot at start                                                                                                                               |
| focus_lost_count      | unsignedInteger, default 0      |                                                                                                                                                 |

### `answers`

| Column                 | Type                   | Notes                                                                                   |
| ---------------------- | ---------------------- | --------------------------------------------------------------------------------------- |
| attempt_id             | FK, cascade            |                                                                                         |
| assessment_question_id | FK                     | Unique with `attempt_id`                                                                |
| question_id            | FK                     | Denormalised for the review inbox                                                       |
| selected_option_ids    | json, nullable         |                                                                                         |
| text_answer            | longText, nullable     |                                                                                         |
| code_answer            | longText, nullable     |                                                                                         |
| flagged                | bool, default false    | The student flagged it "to come back to"                                                |
| answered_at            | datetime, nullable     |                                                                                         |
| max_score              | decimal(8,2)           |                                                                                         |
| score                  | decimal(8,2), nullable |                                                                                         |
| grading_status         | `AnswerGradingStatus`  |                                                                                         |
| ai_score               | decimal(8,2), nullable | The raw AI suggestion, kept even after the instructor overrides it                      |
| ai_feedback            | text, nullable         |                                                                                         |
| ai_confidence          | decimal(3,2), nullable |                                                                                         |
| ai_breakdown           | json, nullable         | Per-criterion notes                                                                     |
| ai_flags               | json, nullable         | e.g. `["prompt_injection","off_topic"]`                                                 |
| review_reasons         | json, nullable         | Why `PublishGate` sent the answer to review, e.g. `["low_confidence","flag:off_topic"]` |
| grading_error          | text, nullable         | The last failure message when the status is `failed`                                    |
| feedback               | text, nullable         | The final feedback shown to the student (AI feedback, or the instructor's edit)         |
| graded_by              | FK users, nullable     | null = AI or automatic                                                                  |
| published_at           | datetime, nullable     |                                                                                         |

### `attempt_events`

`attempt_id`, `type` (`AttemptEventType`), `occurred_at`, `meta` json nullable. No `updated_at`.

### `submissions`

| Column                | Type                       | Notes                                                                                    |
| --------------------- | -------------------------- | ---------------------------------------------------------------------------------------- |
| participant_id        | FK, cascade                |                                                                                          |
| public_id             | ulid, unique               |                                                                                          |
| repo_url              | string                     | Normalised to `https://github.com/{owner}/{repo}`                                        |
| repo_owner, repo_name | string                     |                                                                                          |
| commit_sha            | string(40)                 | HEAD of the default branch at submission time                                            |
| default_branch        | string                     |                                                                                          |
| submitted_at          | datetime                   |                                                                                          |
| is_current            | bool                       | Only the latest submission per participant is current and graded                         |
| minutes_late          | unsignedInteger, default 0 | Worked out when submitted, based on the effective deadline at that time                  |
| status                | `SubmissionStatus`         |                                                                                          |
| manifest              | json, nullable             | Ingestion summary: files included and skipped (paths only), token estimate, commit count |
| raw_score             | decimal(8,2), nullable     | Sum of rule scores                                                                       |
| penalty               | decimal(8,2), default 0    |                                                                                          |
| score                 | decimal(8,2), nullable     | `max(0, raw_score - penalty)`                                                            |
| max_score             | decimal(8,2)               |                                                                                          |
| feedback              | text, nullable             | Overall summary                                                                          |
| graded_by             | FK users, nullable         |                                                                                          |
| published_at          | datetime, nullable         |                                                                                          |
| error                 | text, nullable             |                                                                                          |
| review_reasons        | json, nullable             | Same meaning as on `answers`                                                             |
| ai_flags              | json, nullable             | Flags from `ProjectGrader` (e.g. `prompt_injection`), shown in review (D-026)            |

`minutes_late` and `penalty` are worked out when the student submits, and **recalculated** whenever the participant's overrides change (M09.7). Each submission is evaluated on its own: a late resubmission's penalty replaces the earlier one and is not added to it, because only the current submission counts.

### `submission_rule_results`

| Column             | Type                   | Notes                                   |
| ------------------ | ---------------------- | --------------------------------------- |
| submission_id      | FK, cascade            |                                         |
| assignment_rule_id | FK, cascade            |                                         |
| score              | decimal(8,2)           |                                         |
| max_score          | decimal(8,2)           |                                         |
| passed             | bool, nullable         | For automated checks                    |
| reasoning          | text, nullable         |                                         |
| evidence           | json, nullable         | e.g. matching file paths or commit SHAs |
| ai_confidence      | decimal(3,2), nullable |                                         |
| overridden_by      | FK users, nullable     |                                         |

### `ai_runs` (C)

| Column                      | Type            | Notes                                   |
| --------------------------- | --------------- | --------------------------------------- |
| team_id                     | FK              |                                         |
| purpose                     | `AiRunPurpose`  |                                         |
| subject_type, subject_id    | morphs          | answer, submission, question_generation |
| provider, model             | string          |                                         |
| input_tokens, output_tokens | unsignedInteger |                                         |
| cost_usd                    | decimal(12,6)   |                                         |
| duration_ms                 | unsignedInteger |                                         |
| succeeded                   | bool            |                                         |
| error                       | text, nullable  |                                         |

The prompt and response are **not** stored. They contain student data, and the useful parts already live on the subject.

### `audit_logs` (C)

`team_id`, `user_id`, `subject` (morphs), `action` string (e.g. `grade.override`, `grade.publish`, `participant.override`, `attempt.reset`, `attempt.force_submit`, `attempt.allow_resume`, `question.rubric_update`, `results.release`, `results.unrelease`, `submission.regrade`), `changes` json (before/after), `note` text nullable. No `updated_at`.

## Score rules (in summary; details are in the feature docs)

- Attempt `score` = sum of `answers.score`, once every answer has `grading_status = final`. Until then the attempt stays `grading`.
- Submission `score` = `max(0, raw_score - penalty)`. It is recalculated whenever a rule result, override or penalty changes.
- A student sees a score only when (a) the assessment's results are released **and** (b) that answer or submission has `published_at` set. Anything unpublished shows "Under review".
