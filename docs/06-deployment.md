# 06 — Deployment (Laravel Cloud)

Production runs on **Laravel Cloud**: an app cluster, a MySQL database, **Managed Queues**, and the scheduler. Cloud is managed with the Cloud CLI (`./vendor/bin/cloud`, see the `deploying-to-cloud` skill). The tasks are in [milestones/M12-deploy.md](milestones/M12-deploy.md).

> Every Cloud command needs the user's approval first. Confirm the app, environment, region and sizes. Add `-n` to every command, and run `cloud deploy:monitor -n` after every deploy.

## Topology

| Piece         | Choice                                      | Notes                                                                                                                                   |
| ------------- | ------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------- |
| Environments  | `production` (+ an optional `staging`)      | Each has its own compute and resources                                                                                                  |
| App compute   | Small instance, 1–2 replicas                | We never clone repos or hold big files (D-007). Peak worker memory is about the 100k-token context (~400 KB of text) plus PHP overhead. |
| Database      | Laravel Cloud MySQL 8                       | Connection variables are injected automatically                                                                                         |
| Queues        | Managed Queues: `ai`, `default`             | Autoscale on demand                                                                                                                     |
| Scheduler     | On (app cluster)                            | Runs `schedule:run` every minute                                                                                                        |
| Cache/session | `database` driver (or Laravel Valkey later) | **The filesystem is ephemeral and not shared between replicas.** Never use the `file` driver.                                           |
| File storage  | None in v1                                  | CSV exports are streamed, not stored                                                                                                    |

## Build and deploy commands

- **Build:** `composer install --no-dev --optimize-autoloader && npm ci && npm run build`
    - Wayfinder types are generated at build time by the Vite plugin. If the build complains, it needs `php` available, which Cloud images include.
    - Add optimisation and caching (`php artisan optimize`) to the build step, not the deploy step.
- **Deploy:** `php artisan migrate --force`
- Do **not** add `queue:restart`, `optimize:clear` or `storage:link` to deploy commands. Cloud restarts workers itself, and changes made to the filesystem during deploy don't persist.
- Build and deploy commands time out after 15 minutes.

## Queues

| Queue     | Jobs                                                      | Suggested concurrency                                                        | Worker timeout                                 |
| --------- | --------------------------------------------------------- | ---------------------------------------------------------------------------- | ---------------------------------------------- |
| `ai`      | `GenerateQuestions`, `GradeOpenAnswer`, `GradeSubmission` | 2–4 (keeps us under OpenAI limits; the app's `openai` limiter still applies) | **≥ 340s** (`GenerateQuestions` timeout is 330s) |
| `default` | Mail, notifications, misc                                 | 2                                                                            | 90s                                            |

- Every job sets `$queue` explicitly. AI jobs use `config('evalyst.ai.queue')`.
- **One queue only** (for example a Cloud plan with a single managed queue): set `EVALYST_AI_QUEUE=default`. Everything then runs on `default`, so that worker needs the AI settings: timeout **≥ 340s** and concurrency 2–4. The `openai` rate limiter still caps AI calls. Invitation emails and choice-question scoring can wait behind an AI backlog after a big quiz, which is acceptable. Switch while the `ai` queue is empty (no generation or grading running): jobs already waiting on `ai` stay there once no worker reads it. `grading:recover` re-dispatches stuck grading after 15 minutes, but not question generations.
- The worker timeout must always be longer than the job's `timeout`, and `retry_after` in `config/queue.php` must be longer than both. Otherwise jobs run twice.
- Before adding Managed Queues, check the current Cloud docs (`https://cloud.laravel.com/docs/llms.txt`) for their package requirements (e.g. `aws/aws-sdk-php`).

## Scheduler

`routes/console.php`. Every entry uses `->onOneServer()->withoutOverlapping()`:

- `attempts:expire`: every minute
- `grading:recover`: every 10 minutes
- the starter kit's invitation pruning (keep it)

## Environment variables

| Variable                                                                  | Value / note                                                                                                                      |
| ------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------- |
| `APP_NAME`                                                                | `Evalyst`                                                                                                                         |
| `APP_ENV` / `APP_DEBUG`                                                   | `production` / `false`                                                                                                            |
| `APP_URL`                                                                 | HTTPS custom domain, or the `*.laravel.cloud` domain                                                                              |
| `APP_KEY`                                                                 | set by Cloud / `key:generate`                                                                                                     |
| `DB_*`                                                                    | injected by the attached MySQL                                                                                                    |
| `QUEUE_CONNECTION`                                                        | as Managed Queues requires (follow the Cloud docs)                                                                                |
| `SESSION_DRIVER` / `CACHE_STORE`                                          | `database`                                                                                                                        |
| `OPENAI_API_KEY`                                                          | **secret** (Secrets Manager)                                                                                                      |
| `EVALYST_AI_PROVIDER` / `EVALYST_AI_MODEL`                                | `openai` / `gpt-5.4-mini` (D-005)                                                                                                 |
| `GITHUB_TOKEN`                                                            | **secret**. A fine-grained token with **public repositories read-only** access. Raises the rate limit to 5,000 requests per hour. |
| `MAIL_MAILER`, `MAIL_HOST`/API key, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` | transactional provider for instructor invitations                                                                                 |
| `NIGHTWATCH_TOKEN`                                                        | optional monitoring                                                                                                               |

Keep `.env.example` in sync with this list (M00.5). Store secret values with `echo "$VALUE" | cloud secret:create --name=… --json -n`, then `environment-secret:attach`. Redeploy after any change to variables or secrets.

## Mail

Instructor invitations are the only email. Any transactional provider works. Confirm the invitation link uses `APP_URL` over HTTPS. Students never receive email.

## Backups and data

- Turn on and confirm automated MySQL backups and their retention in Cloud. Record the policy here once it's set: _TBD (M12.3)_.
- `ai_runs` stores no prompts or responses. The only student data is in `students`, `answers` and `submissions`.

## Release checklist

1. `composer test` and `npm run types:check` pass locally, and CI is green on `main`.
2. Push to `main`, which triggers push-to-deploy (or run `cloud deploy {app} {env} -n`).
3. `cloud deploy:monitor -n` until it succeeds.
4. Check the app URL, the logs, that queues are draining, and that the scheduler ran recently.
5. For big releases, run the M12.4 smoke-test checklist.

## Queue `retry_after`

`config/queue.php` defaults `DB_QUEUE_RETRY_AFTER` and `REDIS_QUEUE_RETRY_AFTER` to 360s. That is longer than the longest job timeout (330s for `GenerateQuestions`). Keep it that way in every environment, or a second worker will pick up a job that is still running.
