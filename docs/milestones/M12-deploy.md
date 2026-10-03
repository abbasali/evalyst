# M12 — Deploy to Laravel Cloud

**Goal:** Run Evalyst in production on Laravel Cloud with MySQL, Managed Queues for the `ai` and `default` queues, the scheduler, and working invitation emails. The deploy should be repeatable and checked with a smoke test.

**Depends on:** all functional milestones (M00–M11). M12.1–M12.2 can be done earlier as a staging setup.
**Read first:** [06-deployment.md](../06-deployment.md), and the `deploying-to-cloud` skill (`.claude/skills/deploying-to-cloud/SKILL.md`).

> **Every Cloud command needs the user's explicit go-ahead.** Confirm the application name, region, instance sizes and environment before running anything. Destructive commands (deleting environments, databases or secrets) are never run without approval. Use `./vendor/bin/cloud … -n`, and after every deploy run `cloud deploy:monitor -n`.

---

### M12.1 — Cloud app + MySQL + environment variables

**Build**

1. Install the CLI with `composer require --dev laravel/cloud-cli` (an approved dependency for this task), then `./vendor/bin/cloud auth -n` (the user does this; it opens a browser).
2. Push the repo to GitHub. The first deploy uses `cloud ship -n` with the options the user confirmed (check `cloud ship -h` first). Create a `production` environment, and a `staging` one too if the user wants it.
3. Attach a **MySQL** database (`database-cluster:create` then `database:create`). The connection variables are injected automatically.
4. Set the environment variables and secrets listed in 06-deployment.md. Put `OPENAI_API_KEY` and `GITHUB_TOKEN` in the **Secrets Manager** (pipe the values in; never use `--value`). Redeploy afterwards.
5. Build command: `composer install --no-dev --optimize-autoloader && npm ci && npm run build`. Deploy command: `php artisan migrate --force`.

**Acceptance criteria:** the app loads on its `laravel.cloud` domain, migrations ran, and an instructor can log in after `evalyst:make-instructor` is run through `cloud command:run`.

---

### M12.2 — Managed Queues + scheduler

**Build**

- Before adding queues, check the current Cloud docs (`https://cloud.laravel.com/docs/llms.txt`) for Managed Queue requirements (e.g. `aws/aws-sdk-php`). Add that dependency if it's required (approved for this task).
- Create Managed Queues: `ai` (low concurrency, about 2–4 workers, to stay under OpenAI rate limits and the `openai` limiter), and `default` (about 2). The worker timeout must be **at least 330s**, which is longer than the longest job timeout (300s for `GradeSubmission`).
- Turn on the **scheduler** on the app cluster. Every scheduled command uses `->onOneServer()` (`attempts:expire` every minute, `grading:recover` every 10 minutes, invitation pruning).
- Make sure every job declares its queue (`ai` or `default`), so nothing is dispatched to an unknown queue.

**Acceptance criteria:** a test quiz with one open answer gets AI-graded in production. `attempts:expire` auto-submits an expired attempt. A failed job shows up as `failed` in the review inbox.

---

### M12.3 — Mail, hardening, monitoring, backups

**Build**

- **Mail:** configure a transactional provider (Postmark, Resend or SES, the user's choice) with `MAIL_*` and `MAIL_FROM_ADDRESS`. Send a test invitation.
- **Hardening:**
    - `APP_DEBUG=false`, `APP_ENV=production`, HTTPS `APP_URL`
    - session and cache in the database (or Laravel Valkey if added), never files (the filesystem is ephemeral)
    - check the `throttle:join` and results-page throttles
    - `TrustProxies` works correctly behind Cloud
- **Monitoring:** turn on Laravel Nightwatch, or at least Cloud logs and alerts. Check the logs for failed AI jobs.
- **Backups:** confirm the MySQL backup/retention settings in Cloud and record them in 06-deployment.md.

**Acceptance criteria:** an invitation email arrives and its link works over HTTPS. A deliberately triggered error is visible in monitoring. The backup policy is documented.

---

### M12.4 — Production smoke-test checklist

Run through this list on production (or staging) and record the date and result in the PROGRESS note:

1. Log in as an instructor and switch courses.
2. Invite a second instructor. They accept, register, and see the shared course.
3. Create a question by hand, then generate 3 with AI. The drafts appear and can be accepted.
4. Create a quiz in shared-code mode. Join from a phone using name + roll number. Answer, flag, move between questions, let the timer run low, then submit.
5. The choice score appears instantly. The open answer is AI-graded within about a minute and is either published or in the inbox.
6. Release results. The student results link shows scores and feedback.
7. Create an assignment with one automated rule and one AI rule. Submit a public repo. Check the SHA was recorded and the grading finished, and that the manifest shows `vendor/` and `node_modules/` as skipped and never fetched.
8. Apply a late override to a participant. The score is recalculated and an audit log is recorded.
9. Export the CSVs and check the AI usage panel shows the costs.
10. Check the queues are empty, there are no failed jobs, and the scheduler ran in the last few minutes.

**Acceptance criteria:** all 10 steps pass. Any failures become new tasks in PROGRESS.md.
