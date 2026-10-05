# Feature — Quiz Taking (Student)

## Purpose

Students join with a code, answer one question per screen against a timer enforced by the server, and submit. They never have an account (see 01-architecture.md, "Student side").

## Join: `GET/POST /join` (throttle `join`: 10 per minute per IP)

1. The student enters a code. Matching ignores case and spaces.
2. **Resolve the code:**
    - Matches `participants.access_code` → **roster** join for that participant.
    - Matches `assessments.shared_code` → **shared** join. A second step asks for **Full name** (required, max 100) and **Roll number** (required, max 50).
    - No match → "Invalid code". Show the same message for every failure, so codes can't be guessed.
3. **Shared join:**
    - Find the course student by `(team_id, roll_number)`, or create one with the entered name. If the student already exists, keep the roster name; the entered name is not stored separately.
    - Then `firstOrCreate` the participant `(assessment_id, student_id)`.
4. The assessment must be `published` and not archived:
    - Not open yet: "Opens at {time}".
    - Closed and no attempt: "This quiz has closed".
5. The session stores `student.participant_id`, then redirects to the quiz landing page `/q/{assessment:public_id}`.

## Attempts & resume

- **One attempt per participant** (`attempts.participant_id` is unique).

| Situation                                                                                           | Result                                                                           |
| --------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------- |
| No attempt                                                                                          | Landing page with a **Start** button                                             |
| Attempt in progress, roster mode                                                                    | Resume immediately. The code is the student's own secret.                        |
| Attempt in progress, shared mode, same browser (the `resume_token` cookie matches the hashed token) | Resume                                                                           |
| Attempt in progress, shared mode, different browser or cookie lost                                  | Blocked: "This roll number already has a quiz in progress. Ask your instructor." |
| Attempt submitted                                                                                   | "You have already submitted", plus the results link                              |

- **Instructor "Allow resume"** (on the live monitor) clears the token. The next join from any browser issues a new cookie and resumes. This is written to the audit log.
- **Instructor "Reset attempt"** deletes the attempt and its answers, with a confirmation that says data will be lost. The student can then start fresh. This is written to the audit log.

## Start: `StartAttempt`

Allowed only if the quiz is open (`opens_at ≤ now < closes_at`) and no attempt exists. It does the following in one transaction:

- `started_at = now`
- `deadline_at = min(started_at + duration, closes_at)`
- `max_score` = the sum of the quiz's marks
- `question_order` (shuffled if enabled, otherwise by position), and `option_order` for choice questions (if shuffle options is on)
- `resume_token`: random 64 characters. The hash goes on the attempt, the raw value into a cookie (httpOnly, expires at the deadline + 1 hour)
- An empty `answers` row for each question (`grading_status = ungraded`, `max_score` = its marks)

Then it redirects to question 1.

## Question screen: `/attempts/{attempt:public_id}/questions/{n}`

- **Layout** (`StudentLayout`): the course name and quiz title, the **timer** (top right, always visible), and "Question n of N".
- **Body:** the question Markdown, then the input for its type:
    - `single_choice`: radio list. Options appear in `option_order`, labelled A, B, C… as displayed.
    - `multiple_choice`: checkbox list with "Select all that apply".
    - `open_text`: a textarea that grows, max 10,000 characters, with a character counter.
    - `open_code`: an "Explanation" textarea (optional unless the question asks for one) plus a CodeMirror editor in the question's `code_language`, max 20,000 characters.
- **Footer:** Previous, a **Flag for review** toggle, and Next. On the last question, Next becomes **Review & submit**.
- **Question map:** a grid of numbered buttons (a drawer on mobile, a sidebar on desktop). Each shows its state: current, answered, flagged (can be combined with answered), or unanswered. Clicking one jumps to that question.
- Moving back and forth is unlimited until the student submits.

## Autosave: `PUT /attempts/{attempt}/answers/{assessmentQuestion}`

- Saves when the answer changes (debounced 1.5s), when the student navigates or flags, and when the page is hidden. A small "Saved ✓ / Saving… / Offline, retrying" indicator shows the state.
- **Server checks:**
    - The session's participant owns the attempt, and its status is `in_progress`.
    - `now ≤ deadline_at + save_grace_seconds` (30s). Otherwise 409 → the client goes to the submitted page.
    - Option IDs belong to the question.
- Sets `answered_at`, and `locked_at` on the question if it isn't set yet (D-009).
- An answer that is empty (no options selected and blank text/code) counts as **unanswered**.

## Timer

- The page receives `deadline_at` and the `server_now` ISO timestamp. The client works out the clock offset and counts down using `deadline_at - (Date.now() + offset)`, so the local clock doesn't matter.
- Format `mm:ss`, or `h:mm:ss` when over an hour. A non-blocking warning toast appears at **5 minutes** and at **1 minute**, and the timer turns red in the last minute.
- At 0: flush pending saves, then call submit (`auto_submitted = true`). If the tab was closed, the `attempts:expire` scheduled command auto-submits within a minute. Releasing results also auto-submits the quiz's overdue attempts first, so release doesn't depend on that job having run (D-032).
- On a refresh the timer picks up again from the server values.

## Review & submit

- A summary page: the question map plus counts of **Unanswered** and **Flagged**, with links back to those questions. The button reads **Submit quiz**, and a confirmation dialog warns if any are unanswered.
- `SubmitAttempt`: allowed while `in_progress`. It sets `submitted_at` and `status = submitted`, logs an `auto_submitted` event when the submission was automatic, then dispatches grading (grading-and-review.md). It is idempotent.
- **Confirmation page:** "Submitted at {time}", plus the **results link** `/results/{participant.public_id}` with a copy button and the advice: "Save this link. You'll use it to see your results." Roster students can also re-enter their code later to reach the link.

## Focus tracking (if `track_focus`)

- Listen for `visibilitychange` (hidden) and `window.blur`, debounced 2s. Each time the student leaves, the client records `focus_lost` and then `focus_returned`, batched to `POST /attempts/{attempt}/events`.
- The server increments `focus_lost_count` and stores `attempt_events`. **This is never shown to the student and never blocks them.**

## Instructor live monitor: `/{course}/quizzes/{id}/monitor`

- `usePoll` every 5s. The header shows counts: Not started, In progress, Submitted, Grading/Graded.
- **Table:** roll number, name, status, answered/total, time left (in progress), submitted at, focus-lost count (highlighted when 3 or more), and a link to the attempt detail.
- **Row actions:** Allow resume (shared mode, in progress), Reset attempt, Force submit.
- Roster mode lists everyone on the roster. Shared mode lists only students who have joined.

## Edge cases

- The quiz closes (`closes_at`) during an attempt: `deadline_at` was already capped at the start, so nothing changes.
- The instructor extends `closes_at` after attempts started: existing `deadline_at` values are **not** extended. The duration still applies.
- Two tabs open on the same attempt: last write wins per answer. That's acceptable.
- The network drops: saves retry with backoff, and the "Offline" indicator shows. Answers are also mirrored in `localStorage` per attempt as a fallback and re-sent when the connection returns.

Related tasks: M06.1, M06.2, M06.3, M06.4, M06.5, M06.6, M06.7, M06.8, M06.9, M06.10, M06.11
