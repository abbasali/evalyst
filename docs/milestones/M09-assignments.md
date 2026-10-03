# M09 — Assignments: Setup & Submission

**Goal:** Instructors create GitHub-project assignments with rules and a late policy. Students submit a public repo URL, which is recorded with its HEAD commit SHA. Instructors can override deadline and penalty for any student at any time.

**Depends on:** M05 (assessments, participants, access modes), M06.1–M06.2 (join flow, student session), M08.4 (audit logs).
**Read first:** [features/assignments.md](../features/assignments.md), [04-github-ingestion.md](../04-github-ingestion.md) (the "On submission" section), [02-data-model.md](../02-data-model.md), decisions D-007, D-008.

---

### M09.1 — Migrations/enums

**Build**

- Enums: `LatePolicy`, `PenaltyType`, `LateOverride`, `SubmissionStatus`, `RuleKind`, `AutomatedCheck`.
- Migrations:
    - add the assignment-only columns to `assessments` if M05.1 didn't already create them
    - `assignment_rules`, `submissions`, `submission_rule_results`
    - the override columns on `participants` (if not already there)
- Models: `AssignmentRule`, `Submission`, `SubmissionRuleResult`, with relations and casts. `Participant::currentSubmission()` (`hasOne` where `is_current`).
- Factories with states: `Assessment::factory()->assignment()`, `->withLatePenalty(type, value, cap)`, `AssignmentRule::factory()->automated(check, config)` / `->ai()`, `Submission::factory()->late(minutes)`.

**Acceptance criteria:** `migrate:fresh` works, and the factories create a valid assignment with 3 rules and a submission.

**Tests:** `tests/Feature/Models/AssignmentModelsTest.php` (relations, casts, currentSubmission).

---

### M09.2 — Assignment CRUD form

**Build**

- Routes under `{current_team}/assignments` (resource controller `Instructor\AssignmentController`), with pages `pages/assignments/{Index,Create,Edit,Show}.vue`.
- Form fields:
    - title
    - problem statement (Markdown editor + preview, stored in `instructions`)
    - `opens_at`, `closes_at` (the deadline), entered in the course timezone and stored as UTC
    - access mode
    - release mode
    - auto-publish threshold
    - late policy group: policy, then penalty type, value, cap, grace minutes and hard cutoff (shown only when `penalty`), plus a hard cutoff for `allowed`
    - `allow_resubmission`, `show_rules_to_students`
    - `extra_ignored_paths` (one glob per line)
- `StoreAssignmentRequest`/`UpdateAssignmentRequest`:
    - `closes_at > opens_at`
    - `hard_cutoff_at > closes_at`
    - penalty fields are required when the policy is `penalty`
- Access (roster/shared code) reuses the M05.4/M05.5 components.
- Publishing requires at least one rule and total marks > 0.

**Acceptance criteria:** validation per field, the timezone conversion is correct, and a course-B instructor gets a 404.

**Tests:** `tests/Feature/Assignments/AssignmentCrudTest.php`.

---

### M09.3 — Rule builder

**Build**

- A `components/assignments/RuleBuilder.vue` section on the Edit page. Rules are a sortable list.
- Add **Automated** rule: choose a check, and the config form changes to match:
    - `min_commits {min}`
    - `min_commit_days {min}`
    - `commit_message_pattern {pattern, min_ratio}` with a "Conventional Commits" preset
    - `path_exists {glob, min_matches}`
    - `path_absent {glob}` with presets `vendor/**`, `node_modules/**`, `.env`
    - `file_contains {glob, pattern}`
- Add **AI** rule: title + description (the criterion).
- Every rule has marks. The total is shown.
- Help text on commit checks: "Commit dates come from the student's machine; treat as indicative."
- `SaveAssignmentRulesRequest` validates config per check, including that a regex compiles (`@preg_match`) and that the glob isn't empty.
- Rules can't be edited once a current submission is `final`. The UI offers "Regrade all after editing", which unlocks the rules, saves, and then dispatches regrades (M10.6).

**Acceptance criteria:** an invalid regex is rejected, the ordering is saved, and a missing config key gives a 422.

**Tests:** `tests/Feature/Assignments/RuleBuilderTest.php`.

---

### M09.4 — `LatePenaltyCalculator` + effective deadline

**Build:** `app/Grading/LatePenaltyCalculator.php` (pure). Inputs are the assessment, the participant (overrides), `submittedAt` and `now`.

- `effectiveDeadline = participant.deadline_override_at ?? assessment.closes_at`
- `minutesLate(submittedAt) = max(0, ceil((submittedAt − (effectiveDeadline + grace_minutes)) / 60s))`
- `canSubmit(now): SubmitDecision{allowed, reason}`. The first matching row wins:

| #   | Condition                                                     | Result            |
| --- | ------------------------------------------------------------- | ----------------- |
| 1   | The assessment isn't published, or `now < opens_at`           | ✗ not open        |
| 2   | `late_override = block` and `now > effectiveDeadline + grace` | ✗                 |
| 3   | `late_override = allow`                                       | ✓                 |
| 4   | `now ≤ effectiveDeadline + grace`                             | ✓ on time         |
| 5   | `late_policy = not_allowed`                                   | ✗ deadline passed |
| 6   | `hard_cutoff_at` is set and `now > hard_cutoff_at`            | ✗ cutoff passed   |
| 7   | otherwise                                                     | ✓ late            |

- `penalty(minutesLate)`. The first matching row wins:
    1. `penalty_waived` → 0
    2. `penalty_override` is set → that value
    3. `minutesLate = 0` or the policy isn't `penalty` → 0
    4. Otherwise, by penalty type:
        - `fixed` → `value`
        - `per_hour` → `ceil(min/60) × value`
        - `per_day` → `ceil(min/1440) × value`
    5. Then apply `min(…, penalty_cap)` if a cap is set.
- `finalScore(raw, penalty) = max(0, raw − penalty)`, rounded to 2 decimal places.

**Acceptance criteria:** every row of the table and every penalty type, including the boundaries: exactly at the deadline, one minute after the grace period, cap reached, waived combined with an override.

**Tests:** `tests/Unit/Grading/LatePenaltyCalculatorTest.php` (datasets, fixed `CarbonImmutable` instants).

---

### M09.5 — `GitHubClient` (repository, headCommit) + fixtures

**Build**

- `app/Services/GitHub/GitHubClient.php`, as described in 04-github-ingestion.md. This task covers `repository()` and `headCommit()`.
- `app/Services/GitHub/RepoUrl.php`: parses and normalises the URL, using the rules in 04-github-ingestion.md.
- DTOs: `app/Data/GitHubRepository`, `app/Data/GitHubCommit`.
- Exceptions: `RepositoryNotFound`, `RepositoryPrivate`, `GitHubUnavailable`, `GitHubRateLimited` (with `resetsAt`).
- Fixtures: `tests/Fixtures/github/repository-public.json`, `repository-private.json`, `commit-head.json`.

**Acceptance criteria**

- The URL parser accepts and rejects the documented cases.
- A 404 → `RepositoryNotFound`. 403 with remaining = 0 → `GitHubRateLimited`.
- The token header is sent only when configured.

**Tests:** `tests/Unit/GitHub/RepoUrlTest.php` (dataset) and `tests/Feature/GitHub/GitHubClientTest.php` (`Http::fake`, `Http::preventStrayRequests()`).

---

### M09.6 — Student assignment page + `SubmitRepository`

**Build**

- After joining (M06.2), an assignment participant lands on `pages/student/assignment/Show.vue`. It shows:
    - the statement (Markdown)
    - the rules, if `show_rules_to_students` (titles and marks, not configs)
    - a deadline countdown in the course timezone
    - the late policy in plain words (e.g. "Late submissions lose 2 marks per day, max 10; no submissions after 12 Oct 18:00")
    - the current submission (URL, short SHA, time, on time / N late)
    - the submit form
- `app/Actions/Assignments/SubmitRepository.php`:
    1. `canSubmit(now)`. If not allowed, show the reason.
    2. If a current submission exists, `allow_resubmission` is on, and the deadline hasn't passed (or `late_override = allow`), continue. Otherwise reject.
    3. Normalise the URL, call `repository()` (public check), then `headCommit()`.
    4. In a transaction: mark the old submission `is_current = false` (keep it). Create a new submission with the SHA, `submitted_at`, `minutes_late`, `penalty`, `max_score` (sum of rule marks) and `status = submitted`.
    5. After commit, dispatch `GradeSubmission` (M10.5). Until M10 exists, guard it with a `config('evalyst.assignments.auto_grade')` flag.
- If GitHub fails, the submission isn't created and the student sees a retry message (D-007).
- Old submissions' grading jobs exit early when `!is_current`.

**Acceptance criteria**

- On time → `minutes_late = 0`.
- Late with a penalty → the penalty is stored.
- `not_allowed` after the deadline → rejected.
- A private repo → error, no row created.
- Resubmission → 2 rows, only the newest is current.

**Tests:** `tests/Feature/Student/SubmitRepositoryTest.php` (`Http::fake`, `travelTo`).

---

### M09.7 — Participant overrides + `ApplyParticipantOverride`

**Build**

- An "Overrides" dialog on each participant row (M09.8):
    - deadline override (date-time)
    - late override (follow policy / allow / block)
    - waive penalty
    - custom penalty
    - a note (required)
- `app/Actions/Assignments/ApplyParticipantOverride.php`:
    1. Save the overrides.
    2. If there's a current submission, recalculate `minutes_late` (against the new effective deadline), `penalty`, and `score = max(0, raw_score − penalty)` when `raw_score` is set. If the submission was `final`, it stays published with the new score.
    3. Write the audit log `participant.override` (before/after).
- This works at **any time**: before the deadline, after, or after grading.

**Acceptance criteria:** extending the deadline past `submitted_at` makes the submission on time (penalty 0, score = raw), and an audit row exists.

**Tests:** `tests/Feature/Assignments/ParticipantOverrideTest.php`.

---

### M09.8 — Instructor submissions list

**Build:** `GET {current_team}/assignments/{assessment}/submissions` → `pages/assignments/Submissions.vue`. One row per participant: name, roll number, repo link (to the exact commit: `https://github.com/{o}/{r}/tree/{sha}`), submitted at, late by, penalty, status, raw/penalty/final score, an overrides indicator and the Overrides button, plus a link to the submission detail (M10.6). Filters: status, late only, not submitted. Participants without a submission still appear.

**Acceptance criteria:** every participant is listed, the late filter works, and the commit link uses the stored SHA.

**Tests:** `tests/Feature/Assignments/SubmissionsListTest.php`.
