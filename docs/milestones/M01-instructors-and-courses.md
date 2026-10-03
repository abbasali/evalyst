# M01 — Instructors & courses

**Goal:** turn the starter kit's teams into **Courses** shared by equal instructors. Accounts become invite-only, and the course-scoping groundwork that every later model relies on is put in place.

**Depends on:** M00.
**Read:** `features/instructors-and-courses.md`, `01-architecture.md` §Course scoping, `decisions.md` D-002, D-003, D-004.

---

### M01.1 — Rename "Team" to "Course" in all UI text

**Goal:** instructors never see the word "team". The code keeps `Team` (D-002).

**Build**

- Update text in `resources/js/pages/teams/{Index,Edit}.vue`, `components/{TeamSwitcher,CreateTeamModal,DeleteTeamModal,LeaveTeamModal,InviteMemberModal,RemoveMemberModal,CancelInvitationModal,PendingInvitationsModal,TeamInvitationAlert}.vue`, the settings nav in `layouts/settings/Layout.vue`, and page titles and breadcrumbs.
- Update backend text: toasts and flash messages in `app/Http/Controllers/Teams/*`, validation messages in `app/Rules/TeamName.php` and the `Teams` form requests, and the `app/Notifications/Teams/TeamInvitation.php` mail ("You've been invited to join the course …").
- Leave route URLs as they are (`settings/teams`). Change the nav label only.

**Acceptance criteria**

- `grep -ri "team" resources/js/pages resources/js/components` finds no user-visible text. Identifiers and imports may still contain it.
- The invitation email mentions the course name and says "course".

**Tests**

- Update the existing `tests/Feature/Teams/*` assertions that check text. Add a test that the invitation notification's mail subject contains "course".

### M01.2 — Turn off public registration; accepting an invitation creates the account

**Goal:** the only ways to get an account are an invitation or the artisan command (M01.3).

**Build**

- `config/fortify.php`: remove `Features::registration()`. Remove the register links from `pages/auth/Login.vue` and `Welcome.vue`.
- New guest routes in `routes/web.php`:
    - `GET invitations/{invitation:code}` → `InvitationRegistrationController@show`
    - `POST invitations/{invitation:code}/register` → `@store`
- `show`:
    - An invalid, expired or accepted invitation gives a friendly error page.
    - If the email already belongs to a user, redirect to login with an "accept after login" message.
    - Otherwise render `pages/auth/AcceptInvitation.vue`: course name, the email (read-only), name, password and confirm fields.
- `store`: an action `App\Actions\Teams\RegisterFromInvitation` that, in one transaction:
    - creates the user (email marked verified, since the invitation proves ownership of the email)
    - adds the membership with the invitation's role
    - marks the invitation `accepted_at`
    - sets `current_team_id`
    - logs the user in
    - redirects to that course's dashboard
- Change the invitation mail link to point at `invitations/{code}`.

**Acceptance criteria**

- `GET /register` returns 404.
- A valid invitation lets a new person create an account and lands them in the course.
- Expired, accepted or unknown codes are rejected. An invitation for an email that already has an account goes through the logged-in accept flow that already exists.

**Tests**

- `tests/Feature/Auth/InvitationRegistrationTest.php`: happy path, expired, already accepted, email already registered, validation errors, and that registration is disabled.

### M01.3 — No personal teams; `evalyst:make-instructor`; empty dashboard

**Goal:** users have no "personal team". The first instructor is created from the command line.

**Build**

- `app/Actions/Fortify/CreateNewUser.php`: stop creating a personal team. It's unused now that registration is off, but keep it consistent.
- `app/Console/Commands/MakeInstructor.php` (`evalyst:make-instructor {email} {--name=} {--course=}`): prompts for anything missing (use `Laravel\Prompts`), creates a verified user, and optionally creates the first course through `CreateTeam` with `isPersonal: false`.
- If an authenticated user has no `current_team` (no courses), send them to `GET /courses/start` → `pages/courses/Start.vue`, which shows "Create your first course" using the existing `CreateTeamModal` logic. Change `app/Http/Responses/Concerns/RedirectsToCurrentTeam.php` to use this fallback.
- Hide every UI reference to personal teams.

**Acceptance criteria**

- `php artisan evalyst:make-instructor a@b.test --name=A --course="PHP 101"` creates a user who can log in and lands on the PHP 101 dashboard.
- A user with no courses sees the start page and can create one.

**Tests**

- Command test with expected output and the DB state. A login redirect test for a user with no course.

### M01.4 — Equal permissions for all members; hide role UI; owner-only delete

**Goal:** implement D-003.

**Build**

- `app/Enums/TeamRole.php`: `Admin` and `Member` get every `TeamPermission` except `DeleteTeam`. `Owner` keeps everything.
- `CreateTeamInvitationRequest`: drop `role` from the input and always use `TeamRole::Admin`. `UpdateTeamMemberRequest` and its route stay, but the UI never calls them.
- Remove the role selectors and role badges from `InviteMemberModal.vue` and `pages/teams/Edit.vue`. Show "Owner" only next to the course creator.

**Acceptance criteria**

- Any member can invite and remove members (but not the owner) and rename the course. Only the owner can delete it.

**Tests**

- Policy tests for each permission × role, and that the invite request ignores a `role` sent in the input.

### M01.5 — Course settings: description + timezone

**Build**

- Migration: add `description` (text, nullable) and `timezone` (string, default `Asia/Kolkata`) to `teams`. Update the `Team` model (`#[Fillable]`, the `@property` docs) and `TeamFactory`.
- `SaveTeamRequest`: `description` nullable|max:2000, `timezone` must be a valid timezone (`timezone:all`).
- `pages/teams/Edit.vue`: a description textarea and a searchable timezone select.
- Share `currentCourse.timezone` through `HandleInertiaRequests`. Add a frontend helper `resources/js/lib/datetime.ts` with `formatInCourseTz(iso, opts)`.

**Acceptance criteria**

- The timezone is saved and validated, and dates in the UI use it.

**Tests**

- Validation (bad timezone rejected), update persists, a non-member gets 403.

### M01.6 — `BelongsToCourse` trait, scoped route group, isolation test helper

**Goal:** the safety net every course-owned resource will use.

**Build**

- `app/Concerns/BelongsToCourse.php`:
    - `team()` BelongsTo
    - `scopeForCourse(Builder, Team)`
    - on `creating`: if `team_id` is empty and there is an authenticated user with a current team, fill it in
- `routes/web.php`: a named group inside the `{current_team}` prefix, `Route::prefix('{current_team}')->middleware([...])->scopeBindings()->group(base_path('routes/instructor.php'))`. All instructor domain routes go in `routes/instructor.php`.
- `app/Policies/CoursePolicy` helper (or a base `Concerns\AuthorizesCourseMembers` trait) with `member(User, Model): bool` that checks `$user->belongsToTeam($model->team)`.
- In `tests/Pest.php`:
    - `actingAsInstructor(?Team $team = null): array{User, Team}`
    - `assertCourseIsolated(callable $makeModel, callable $requestAs)`: creates a model in course B and asserts that a member of course A gets 403 or 404

**Acceptance criteria**

- A sample route in `routes/instructor.php` resolves with scoped bindings. The helpers are used by at least one test.

**Tests**

- Unit tests for the trait (the scope, filling `team_id` on create). A dummy-route isolation test.

### M01.7 — Course-aware sidebar navigation skeleton

**Build**

- `components/AppSidebar.vue` / `NavMain.vue` items: Dashboard, Question Bank, Quizzes, Assignments, Students, Review (with a badge placeholder). The links go through Wayfinder routes in `routes/instructor.php`. Routes that aren't built yet render `pages/ComingSoon.vue`. Remove them as their milestones land.
- `TeamSwitcher` stays at the top as the "Course switcher".

**Acceptance criteria**

- Every nav link works and stays inside the current course slug. Switching course keeps you on the same section.

**Tests**

- A feature test that each nav route returns 200 for a member and 403 for a non-member.

### M01.8 — `audit_logs` table + `RecordAudit` action

**Goal:** have audit logging in place before the first manual override (M06.8 reset attempt), so no later task needs a stopgap.

**Build**

- A migration and an `AuditLog` model exactly as in `02-data-model.md` (`audit_logs`, course-owned, morph `subject`, no `updated_at`).
- `app/Actions/Audit/RecordAudit::handle(User $user, Model $subject, string $action, array $changes = [], ?string $note = null): AuditLog`. It takes `team_id` from the subject's `team_id`, or from `$subject->team` when the subject is reached through a relation.
- `Model::auditLogs()` morphMany through a small `HasAuditLogs` trait.

**Acceptance criteria**

- Calling `RecordAudit` stores the user, subject, action, before/after `changes` and note under the subject's course.

**Tests**

- A unit/feature test for `RecordAudit`. The isolation helper shows that audit logs are scoped to the course.
