---
name: evalyst-github-ingestion
description: Build or change Evalyst's GitHub repository handling — repo URL submission, GitHubClient, PathFilter, RepositoryIngestor/RepoSnapshot, automated assignment checks, GradeSubmission pipeline, and their tests. Use whenever touching app/Services/GitHub, assignment submission/grading, or tests/Fixtures/github.
---

# Evalyst GitHub Ingestion

## Read first

- `docs/04-github-ingestion.md` is the source of truth: API endpoints, the ignore list, priority order, token budget, and check semantics.
- `docs/decisions.md` D-007 (never clone).
- `docs/features/assignments.md` covers late policy, overrides and resubmission.

## Hard rules

- **No `git clone`, no tarball or zipball, no writing to disk.** Use the REST API and `raw.githubusercontent.com` at the recorded `commit_sha` only.
- **Filter before fetching.** Get the recursive tree, apply `PathFilter` (global `config('evalyst.github.ignored_paths')` plus the assessment's `extra_ignored_paths`), then download only what is left. `vendor/`, `node_modules/`, build output, lock files, binaries and files over `max_file_bytes` are never downloaded.
- **Path-based checks use the unfiltered tree**, so a committed `vendor/` or `.env` can still be penalised without downloading it.
- **Stream within the budget.** Fetch one file at a time in priority order and stop when the token estimate (`bytes/4`) reaches `max_context_tokens`. Don't keep response objects around. Skip non-UTF-8 files and files containing NUL bytes.
- **Never store file contents.** `submissions.manifest` holds paths, skip reasons, counts and the token estimate only.
- A submission needs a resolved `commit_sha`. If GitHub is unavailable when the student submits, reject the submission with a retry message instead of accepting it without a SHA.
- On rate limits (`X-RateLimit-Remaining: 0`, 403/429 with a reset header), throw `GitHubRateLimited`. Jobs `release()` until the reset time.

## Testing

- Use `Http::fake()` with JSON fixtures in `tests/Fixtures/github/` (repo, commit, tree including `vendor/` and `node_modules/` entries, commits pages, raw files). Use `Http::preventStrayRequests()`.
- Assert that no request is ever made for an ignored path. This is the key regression test.
- `PathFilter`, each check, and the priority ordering are pure: write table-driven unit tests for them.
- Lateness and penalty logic belong to `App\Grading\LatePenaltyCalculator`. Test it with datasets and `$this->travelTo()`.
