# Feature — Instructors & Courses

## Purpose

Several instructors share a **course** (the starter kit's `Team`, see D-002), and everything inside it is shared. Accounts are invite-only (D-004), and all members are equal (D-003).

## Starter kit behaviour we build on

- `team_invitations`: `code` (64 chars), `email`, `role`, `invited_by`, `expires_at` (now + 3 days), `accepted_at`.
- `TeamInvitationController@store` sends the `TeamInvitation` mail. Its link currently points to `route('login', ['invitation' => code])`.
- `accept` and `decline` (`POST/DELETE invitations/{invitation}`) require an authenticated user. Accepting creates a membership with the invitation's role, sets `accepted_at`, and switches the current team.
- Course management lives under `settings/teams/*`: index, edit, members, invitations, switch, leave, delete. Instructor app routes are prefixed with `{current_team}` (slug) and use `EnsureTeamMembership`.

## Changes

### Invitation-based sign-up

1. The invite mail links to a new **guest** route, `GET /invitations/{code}`, which shows the "Join _Course name_" page.
2. If the invitation is unknown, expired, or already accepted, show an error page: "This invitation is no longer valid. Ask a colleague to invite you again."
3. **No account exists for the invite email:** show a registration form with name, email (pre-filled and **read-only**), password and password confirmation. Submitting creates the user (email is treated as verified, because they received the mail), creates the membership, marks the invitation accepted, sets the current team, logs them in, and redirects to the course dashboard.
4. **An account exists but the visitor is a guest:** go to login, keeping `?invitation=code`. After login, show the accept/decline prompt (the starter kit's `PendingInvitationsModal` already handles this).
5. **Logged in as a different email:** show "This invitation was sent to x@…. Log out to accept it."
6. Fortify `Features::registration()` is removed. `/register` returns 404.

### First instructor and empty state

- `php artisan evalyst:make-instructor {email} {name} {course?}` creates a verified user and prompts for a password (hidden input, or `--password=` for scripts). If `{course}` is given, it also creates a course owned by that user. It is idempotent on email: if the user exists, it only adds the course.
- `CreateNewUser` / the invitation flow **never** creates a personal team (`is_personal` stays false).
- A user with no courses who logs in lands on `/courses/create`, an empty-state page: "Create your first course" (name, description, timezone), or "waiting for an invitation" text.

### Permissions (D-003)

| Action                                                                                                       | Who                         |
| ------------------------------------------------------------------------------------------------------------ | --------------------------- |
| Everything inside a course (questions, assessments, students, grading, settings, inviting, removing members) | Any member                  |
| Delete the course                                                                                            | Owner (creator) only        |
| Leave the course                                                                                             | Any member except the owner |

- Map this onto the starter kit by giving `Admin` and `Member` every `TeamPermission` except `DeleteTeam`, or simply invite as `admin` and let `TeamPolicy` allow all members. Use whichever touches the least code, and note it in `decisions.md`.
- Hide role selectors and role badges in the UI. The invite form only asks for an email.

### UI wording

Every user-facing "Team" becomes "Course": sidebar switcher, settings pages, modals, mails, toasts, page titles. Code identifiers, routes and table names stay `team`.

## Course settings

Screen: `settings/teams/{team}` (the starter kit's Edit page), relabelled "Course settings".

| Field       | Rules                                                                                                                                                |
| ----------- | ---------------------------------------------------------------------------------------------------------------------------------------------------- |
| Name        | required, max 100. The slug regenerates (starter kit behaviour).                                                                                     |
| Description | optional, max 2000                                                                                                                                   |
| Timezone    | required, a valid `timezone_identifiers_list()` value, default `Asia/Kolkata`. Every date shown to instructors and students for this course uses it. |
| Members     | list, invite by email, cancel invitation, remove member                                                                                              |

## Students roster

A course-level roster (D-011). Screen: `/{course}/students`.

- **Index:** search by name or roll number, paginated 25 per page. Columns: roll number, name, email, total score (the sum of graded scores across published and archived assessments, released or not; `—` when nothing is graded yet). An **Export** menu offers the all-scores CSV and the gradebook (see results-and-exports.md).
- **Create/edit form:**
    - name: required, max 100
    - roll_number: required, max 50, trimmed, **unique per course**
    - email: optional, valid email
- **Delete:** allowed only if the student has no participants with attempts or submissions. Otherwise show: "This student has assessment records."
- **CSV import:**
    1. Upload a CSV, up to 1 MB and 2000 rows. Columns are matched by header, case-insensitively: `name`, `roll_number` (also accepts `roll`, `roll no`), `email`.
    2. A preview table shows each row's status: **new**, **update** (the roll number exists and the name or email differs), **unchanged**, or **error** (with the reason: missing name or roll, bad email, roll number duplicated within the file).
    3. Confirm imports only the valid rows. It upserts by `(team_id, roll_number)` in a transaction, then shows a toast with the counts. Rows with errors are skipped and listed.
- Students are also created automatically when someone joins a shared-code assessment (see quiz-taking.md).

## Edge cases

- Removing a member keeps everything they created. `created_by` and `graded_by` foreign keys are null-on-delete.
- An expired invitation can be re-sent by inviting the same email again (the starter kit's `UniqueTeamInvitation` rule must allow this when the old one has expired).
- If a course is soft-deleted, its scoped routes return 404. Student codes for it stop working.

Related tasks: M01.1, M01.2, M01.3, M01.4, M01.5, M01.6, M01.7, M02.1, M02.2, M02.3
