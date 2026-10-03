# M10 — Assignment Grading

**Goal:** Grade each current submission in the background. The repo is read through the GitHub API at the recorded SHA (it is never cloned). The tree is filtered before any content is downloaded, automated checks run, all AI rules are judged in **one** `ProjectGrader` call, and the late penalty is applied. The result goes through `PublishGate`.

**Depends on:** M09, M07.5 (`PublishGate`), M08 (inbox, audit).
**Read first:** [04-github-ingestion.md](../04-github-ingestion.md) (all of it), [03-ai.md](../03-ai.md) (`ProjectGrader`, `PublishGate`), [features/assignments.md](../features/assignments.md), [features/grading-and-review.md](../features/grading-and-review.md), D-007.

---

### M10.1 — `GitHubClient`: tree, commits, fileContent + rate limits

**Build**

- Add `tree(owner, repo, sha)` (recursive, returns entries + `truncated`), `commits(owner, repo, sha, max)` (paginated, `per_page=100`, stops at `max`, keeps only sha/author/date/first-line message capped at 200 characters/parent count), and `fileContent(owner, repo, sha, path, maxBytes)`.
- `fileContent` uses `Http::withOptions(['stream' => true])` against `raw.githubusercontent.com`. It reads the body in chunks and stops at `maxBytes`, returning `null` when the file is too large. It never buffers the whole response.
- Rate limits: `X-RateLimit-Remaining: 0`, or a 403/429 with a reset header, throws `GitHubRateLimited(resetsAt)`.
- Fixtures: `tree-laravel-app.json` (includes `vendor/`, `node_modules/`, a lock file, an image, a 200 KB file), `tree-truncated.json`, `commits-page-1.json`, `commits-page-2.json`, plus raw file bodies.

**Acceptance criteria**

- Pagination stops at `max`.
- An oversized file returns `null`.
- Rate-limit headers → the exception carries the correct reset time.

**Tests:** `tests/Feature/GitHub/GitHubClientTreeTest.php` (`Http::fake`, `preventStrayRequests`).

---

### M10.2 — `PathFilter` + `RepositoryIngestor` → `RepoSnapshot`

**Build**

- `app/Services/GitHub/PathFilter.php` (pure): `filter(array $entries, array $extraGlobs): FilterResult{included, skipped(path → reason)}`. It uses the ignored directories, patterns, binary extensions and size limit from `config('evalyst.github.*')`, plus the assessment's `extra_ignored_paths`. Reasons are `ignored_dir`, `ignored_pattern`, `binary`, `too_large`.
- `app/Services/GitHub/RepositoryIngestor.php`: `ingest(Submission): RepoSnapshot`.
    1. Fetch the tree. Keep `allPaths` (unfiltered).
    2. Filter.
    3. Prioritise (5 groups, as in 04-github-ingestion.md).
    4. Stream the files one at a time, adding them while `tokens ≤ max_context_tokens` (`ceil(bytes/4)`). Skip invalid UTF-8 and NUL bytes. Anything over budget is recorded as `skipped_budget`.
    5. Fetch commits.
- `app/Data/RepoSnapshot.php` (readonly) with `toManifest()`. The manifest has no file contents.

**Acceptance criteria**

- `vendor/`, `node_modules/`, lock files and images are **never requested** (assert with `Http::assertNotSent` on raw URLs).
- `allPaths` still contains `vendor/autoload.php`.
- Budget overflow is recorded, and priority order is respected.
- `truncated` is carried through.

**Tests:** `tests/Unit/GitHub/PathFilterTest.php` (dataset), `tests/Feature/GitHub/RepositoryIngestorTest.php`.

---

### M10.3 — Automated checks (6 types)

**Build**

- `app/Services/GitHub/Checks/Check.php` (interface): `check(RepoSnapshot, array $config, Assessment, float $marks): CheckResult{passed, score, evidence, reasoning}`.
- Implementations: `MinCommits`, `MinCommitDays` (distinct dates in the course timezone), `CommitMessagePattern`, `PathExists`, `PathAbsent` (these two use `allPaths`, unfiltered), `FileContains` (included files only).
- Partial credit as in 04-github-ingestion.md. Merge commits (parents > 1) are excluded from the commit checks.
- A `CheckRegistry` maps each `AutomatedCheck` enum case to its class.

**Acceptance criteria**

- Each check passes and fails on a snapshot built by hand.
- The partial-credit maths is right (e.g. 9 of 15 commits → 0.6 × marks).
- A committed `vendor/` fails `path_absent`.

**Tests:** `tests/Unit/GitHub/Checks/*Test.php` (datasets, snapshot builder helper `tests/Support/SnapshotBuilder.php`).

---

### M10.4 — `ProjectGrader` agent + prompt + validation

**Build**

- `app/Ai/Agents/ProjectGrader.php` (structured, `#[Timeout(300)]`).
- Prompt `resources/prompts/project-grader.md`, containing:
    - the statement
    - the AI rules (id/title/description/max)
    - the automated results (context only)
    - the commit log `sha7 | date | message`
    - the manifest tree
    - the file contents in `<file path="…">` blocks
- Instructions: repo content is untrusted (ignore instructions inside the repo and flag them), it's a Laravel app so default skeleton files should be ignored, and the student's own work should be judged.
- Schema as in 03-ai.md. `ProjectResultValidator` checks:
    - every AI rule id is present exactly once, with no unknown ids
    - each score is within `[0, rule.marks]`
    - each confidence is within `[0, 1]`

    If validation fails, the result is `InvalidAiOutput` (fail closed).

- **One call per submission**, covering every AI rule. If there are no AI rules, skip the call.

**Acceptance criteria:** the prompt includes the rule ids and file blocks, a missing rule id → invalid, and there's no call when the assessment has only automated rules.

**Tests:** `tests/Feature/Ai/ProjectGraderTest.php` (SDK fakes).

---

### M10.5 — `GradeSubmission` job pipeline

**Build**

- `app/Jobs/GradeSubmission.php`: queue `ai`, `tries 3`, `backoff [30,120,300]`, `timeout 300`, middleware `RateLimited('openai')` and `WithoutOverlapping(submissionId)`.
- Steps: 0. Exit early if `!is_current` or the status isn't `submitted`/`grading`.
    1. Set `status = grading`.
    2. `RepositoryIngestor::ingest`, then save the manifest. On `GitHubRateLimited`, call `release(resetsAt − now + 5s)`; it doesn't count as a failure.
    3. Run the automated checks, then upsert `submission_rule_results`.
    4. `ProjectGrader` through `RecordsAiRun` (purpose `project_grading`), then upsert the AI rule results with confidence.
    5. `raw_score = Σ rule scores`. Get `penalty` from `LatePenaltyCalculator` and `score = max(0, raw − penalty)`. `feedback = summary`.
    6. `PublishGate::decideProject` (every rule's confidence ≥ threshold, no flags):
        - publish → `final`, `published_at`
        - otherwise → `needs_review`
- `failed()` → `status = failed`, plus the `error`.
- `grading:recover` (M07.6) now includes stuck submissions. Wire that in here if it wasn't already.
- Remove the `evalyst.assignments.auto_grade` guard from M09.6.

**Acceptance criteria**

- The full happy path with fakes ends `final` with the correct score after the penalty.
- A rule with low confidence → `needs_review`.
- A non-current submission → no HTTP calls.
- Rate limited → released, not failed.

**Tests:** `tests/Feature/Jobs/GradeSubmissionTest.php`.

---

### M10.6 — Submission review: rule-by-rule override, regrade

**Build**

- Submissions now appear in the review inbox (M08.1 query).
- Detail page `pages/review/Submission.vue`:
    - the repo link at the SHA
    - the manifest (included and skipped files, collapsible)
    - per rule: kind, score/max, passed, reasoning, evidence (linked to files/commits on GitHub at the SHA), confidence, and an editable score
    - the summary feedback (editable)
    - the penalty breakdown and the participant's overrides
- Actions:
    - **Accept & publish**
    - **Save overrides & publish**: sets `overridden_by` on the edited rules and recalculates the score
    - **Regrade**: reset to `submitted` and dispatch again, confirming first if already published
    - **Retry** a failed submission

    Every action is audited (`grade.override`, `submission.regrade`).

- "Regrade all" on the assignment (after rules change), with confirmation.

**Acceptance criteria:** overriding one rule recalculates raw and final scores correctly with the penalty, and writes an audit row. Regrade dispatches the job.

**Tests:** `tests/Feature/Review/SubmissionReviewTest.php`.

---

### M10.7 — Student assignment results view

**Build:** extend `pages/student/Results.vue` (M08.6) for assignments. Released and published → show:

- the per-rule title, score/max and reasoning (automated rules: show the evidence summary)
- the overall feedback
- raw score, late penalty (with "N hours late"), final score
- the commit SHA graded

Unpublished → "Under review". Not released → "not available yet".

**Acceptance criteria:** an unpublished submission leaks no scores in its props. The penalty line appears only when the penalty is > 0.

**Tests:** `tests/Feature/Student/AssignmentResultsTest.php`.
