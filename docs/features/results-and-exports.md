# Feature — Results, Analytics & Exports

## Quiz results: `/{course}/quizzes/{id}/results`

- **Header:** participants, submitted, graded, needing review, average score (graded only), and release status with the **Release / Unrelease** button.
- **Table:** roll number, name, status (not started / in progress / submitted / grading / graded), score/max (or "—"), submitted at (course timezone, with an "auto" badge for auto-submitted), focus-lost count (highlighted when 3 or more), and a link to the attempt detail.
- Sortable by name, roll number, score and submitted at. Filterable by status.

### Attempt detail: `/{course}/attempts/{attempt}`

- The student, timing (started, deadline, submitted, auto-submitted), and a focus event timeline.
- Every question in the student's order, showing:
    - their answer (selected options marked against the correct ones; text and highlighted code)
    - grading status, score/max, feedback
    - the AI details (confidence, flags, breakdown, `ai_score` if it was overridden)
    - the audit trail
- Each answer has an **Edit grade** action (the same form as the review inbox).

## Assignment results

This is the submissions list described in `assignments.md`, plus the same header and release controls as quizzes. Each row links to the submission detail (the review view).

## Question analytics: `/{course}/quizzes/{id}/analytics`

Graded attempts only. For each question:

- **Average score %** = the mean of `score/max_score`
- **Full marks %**
- **Difficulty index** = the proportion with full marks. Labels: easy above 0.8, hard below 0.3.
- **Choice questions:** an option pick distribution (a horizontal bar per option, the correct ones marked) and the most common wrong option.
- **Open questions:** the score histogram (5 bins) and how many needed review.

Use the `dataviz` skill conventions when building these charts.

## CSV exports

These are generated in the request when small (≤ 500 rows), and otherwise as a queued job with a download link. The first row is the header. UTF-8 with a BOM, so Excel opens it correctly.

| Export             | Columns                                                                                                                    |
| ------------------ | -------------------------------------------------------------------------------------------------------------------------- |
| Quiz results       | roll_number, name, status, submitted_at, auto_submitted, focus_lost, Q1 … Qn (score), total, max                           |
| Assignment results | roll_number, name, submitted_at, minutes_late, commit_sha, rule_1 … rule_n (score), raw_score, penalty, score, max, status |
| Course gradebook   | roll_number, name, then one column per assessment (published final score, blank otherwise), total                          |

- Instructor exports show **all** scores, published or not. A `status` column makes unpublished ones visible.
- The gradebook includes only published scores.
- Times are given in the course timezone, ISO 8601.

## AI cost panel: `/{course}/settings/ai-usage`

- Totals for this month and for all time (USD), broken down by purpose (generation, verification, open answers, projects).
- A table of the last 50 runs: time, purpose, subject link, model, tokens in/out, cost, duration, succeeded.
- A line chart of daily spend over the last 30 days.

## Course dashboard: `/{course}/dashboard`

Widgets:

- **Needs review:** a count with a link to the review inbox
- **Open now:** quizzes and assignments, each with submitted/total participants and the closing time
- **Upcoming:** the next 5 by `opens_at`
- **Recently closed:** results not yet released (with a Release button)
- **Recent activity:** the last 10 audit log entries
- **AI spend this month:** a small figure linking to the cost panel

Related tasks: M08.5, M08.6, M11.1, M11.2, M11.3, M11.4
