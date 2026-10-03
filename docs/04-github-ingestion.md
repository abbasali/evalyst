# 04 — GitHub Ingestion

We read student repositories **only through the GitHub REST API**, at a fixed commit (D-007). There is no `git clone`, no tarball or zipball download, and nothing is written to disk.

## Client: `App\Services\GitHub\GitHubClient`

- Uses Laravel's HTTP client, with base URL `https://api.github.com`, headers `Accept: application/vnd.github+json` and `X-GitHub-Api-Version: 2022-11-28`, and `Authorization: Bearer {config('evalyst.github.token')}` when a token is set. A token raises the rate limit from 60 to 5,000 requests per hour.
- Timeout is 15s. On 5xx or connection errors it retries twice with backoff.
- **Rate limits:** when `X-RateLimit-Remaining` is 0 or a 403/429 comes back with a reset header, throw `GitHubRateLimited` with the reset time. Jobs catch it and `release()` until the reset.
- Methods (each returns a DTO or array, never a raw response):
    - `repository(owner, repo)`: `GET /repos/{o}/{r}` gives `private`, `default_branch`, `size`, `archived`
    - `headCommit(owner, repo, branch)`: `GET /repos/{o}/{r}/commits/{branch}` gives the SHA and date
    - `tree(owner, repo, sha)`: `GET /repos/{o}/{r}/git/trees/{sha}?recursive=1` gives `[path, type, size]` and the `truncated` flag
    - `commits(owner, repo, sha, max)`: `GET /repos/{o}/{r}/commits?sha={sha}&per_page=100`, paginated up to `max` (500)
    - `fileContent(owner, repo, sha, path)`: `GET https://raw.githubusercontent.com/{o}/{r}/{sha}/{path}`, read as a **stream** and stopped at `max_file_bytes`
- Tests: `Http::fake()` with fixture JSON in `tests/Fixtures/github/`. Tests never call the real network.

## On submission (synchronous, inside the submit request; it's fast)

1. **Normalise the URL.** Accept `https://github.com/{owner}/{repo}` with an optional `.git` or trailing slash, and also `http`/`www`. Reject anything else, including other hosts or URLs pointing at `/tree/...`. Regex for owner and repo: `[A-Za-z0-9-_.]+`.
2. Call `repository()`. If the repo returns 404 or `private = true`, show the error "Repository must be public and exist." If it is archived, allow it.
3. Call `headCommit(default_branch)` and store `commit_sha`, `default_branch`, and `submitted_at = now()`.
4. If GitHub is down or rate-limited, show "GitHub is unavailable, please retry in a minute". **We do not accept a submission without a SHA**, because the timestamp and the SHA must belong together.

## Ingestion during grading (`RepositoryIngestor`)

### Step 1: the tree, then filter (before downloading anything)

Fetch the recursive tree at `commit_sha`. If GitHub reports it as `truncated` (a very large repo), carry on with what came back, record `manifest.truncated = true`, and add the `repo_too_large` flag.

Remove an entry if **any** of these match:

**Ignored directories** (any path segment matches): `vendor`, `node_modules`, `.git`, `storage`, `bootstrap/cache`, `public/build`, `public/hot`, `public/storage`, `dist`, `build`, `coverage`, `.idea`, `.vscode`, `.fleet`, `.next`, `.nuxt`, `.cache`, `__pycache__`, `.venv`, `venv`, `target`, `.phpunit.cache`, `.pest`, `.turbo`.

**Ignored files and patterns:** `*.lock`, `composer.lock`, `package-lock.json`, `yarn.lock`, `pnpm-lock.yaml`, `bun.lockb`, `*.min.js`, `*.min.css`, `*.map`, `.env`, `.env.*` except `.env.example`, `*.log`, `*.sqlite`, `*.sqlite3`, `.DS_Store`, `*.phar`.

**Binary and media extensions:** images (`png jpg jpeg gif webp ico bmp svg`*), fonts (`woff woff2 ttf otf eot`), archives (`zip tar gz rar 7z`), media (`mp3 mp4 mov webm wav`), `pdf`, `exe`, `dll`, `so`, `dylib`, `bin`.
\*`svg` is skipped too. It is text, but it's noise for grading.

**Size:** any blob over `max_file_bytes` (100 KB) is skipped and recorded.

The assessment's `extra_ignored_paths` (globs) are added to this list. The list lives in `config('evalyst.github.ignored_paths')` as `directories` (path segments or segment sequences), `files` (globs matched against the basename and the full path) and `extensions`, and is applied by one pure, unit-tested class, `PathFilter`.

**Committed junk is still evidence.** Automated checks like `path_absent: vendor/**` or `path_absent: .env` run against the **unfiltered** tree paths, because they only need the paths, not the contents. So "the student committed vendor/" can still cost marks without us downloading vendor.

### Step 2: prioritise

Order the remaining files by priority group, then by path:

1. `README*`, `composer.json`, `package.json`
2. `routes/**`, `app/**`, `database/migrations/**`, `config/*.php` (only non-default ones if we can tell; otherwise all)
3. `resources/views/**`, `resources/js/**`, `resources/css/**`
4. `tests/**`, `database/seeders/**`, `database/factories/**`
5. everything else

A Laravel skeleton includes many untouched default files. That's acceptable, because the AI is told it's a Laravel app and to focus on the student's own code. Optional improvement: skip files whose blob SHA matches a known Laravel skeleton SHA list. That's out of scope for v1.

### Step 3: stream within the budget

- Estimate tokens as `ceil(bytes / 4)`. Add files in priority order until `max_context_tokens` (100k) is reached. Files that don't fit are recorded in the manifest as `skipped_budget`.
- Fetch files **one at a time** with `fileContent()` and append each to a single string builder. Never hold the response objects. Peak memory is roughly the budget (~400 KB of text) plus overhead.
- Skip a file if it isn't valid UTF-8 after fetching, or if it contains NUL bytes.

### Step 4: commits

`commits()` up to `max_commits`, keeping only: `sha`, `author name`, `author date`, `message (first line, max 200 chars)`, `parents count` (to spot merges). Use them for automated checks and as a compact log for the AI.

### Output: `App\Data\RepoSnapshot` (in memory only)

`owner`, `repo`, `sha`, `allPaths` (unfiltered), `includedFiles` (path → content), `skipped` (path → reason), `commits`, `tokenEstimate`, `truncated`.

`submission.manifest` stores everything **except file contents**: the included paths, skipped paths with reasons, the counts, and the token estimate.

## Automated checks (`App\Services\GitHub\Checks\*`)

Each check implements `check(RepoSnapshot $s, array $config, Assessment $a): CheckResult{passed, score, evidence, reasoning}`.

| Check                    | Config                 | Logic                                                                                                                  |
| ------------------------ | ---------------------- | ---------------------------------------------------------------------------------------------------------------------- |
| `min_commits`            | `{min}`                | Number of non-merge commits ≥ min. Linear partial credit: `score = marks * min(1, count/min)`.                         |
| `min_commit_days`        | `{min}`                | Number of distinct author dates (course timezone) ≥ min. Linear partial credit.                                        |
| `commit_message_pattern` | `{pattern, min_ratio}` | Share of non-merge commit messages matching the regex ≥ `min_ratio`. Partial credit is `ratio/min_ratio`, capped at 1. |
| `path_exists`            | `{glob, min_matches?}` | Matches in the **unfiltered** tree ≥ `min_matches` (default 1). Pass/fail.                                             |
| `path_absent`            | `{glob}`               | No matches in the unfiltered tree. Pass/fail.                                                                          |
| `file_contains`          | `{glob, pattern}`      | At least one _included_ file matching the glob contains the regex. Pass/fail.                                          |

Commit dates are set by the student's machine and can be faked. Treat them as hints and say so in the rule builder's help text.
