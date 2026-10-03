# M02 — Students roster

**Goal:** a roster of students (name + roll number) for each course, reused across assessments (D-011), with CSV import.

**Depends on:** M01.
**Read:** `features/instructors-and-courses.md` (Students section), `02-data-model.md` §students.

---

### M02.1 — `students` migration, model, factory, policy

**Build**

- Migration `create_students_table`: `team_id` FK (cascade, indexed), `name`, `roll_number` string(50), `email` nullable, timestamps. Unique on (`team_id`, `roll_number`).
- `App\Models\Student` uses `BelongsToCourse` and `HasFactory`, and has `participants()` HasMany (the relation lands in M05; add it then).
- `StudentFactory`. `StudentPolicy` (viewAny, view, create, update, delete), each a member check.
- Normalise roll numbers: trim and uppercase in a mutator, so `cs-01` and `CS-01` are the same student.

**Acceptance criteria**

- Two courses can each have roll `CS-01`. The same course cannot have two.

**Tests**

- Model test for the uniqueness and normalisation rules. Policy test that a non-member is denied.

### M02.2 — Students index + create/edit/delete

**Build**

- `routes/instructor.php`: `Route::resource('students', Instructor\StudentController::class)->except('show')`.
- `StoreStudentRequest` / `UpdateStudentRequest`: name required|max:255, roll_number required|max:50 and unique within the course (ignore the current record on update), email nullable|email.
- `pages/students/Index.vue`:
    - a table with search over name and roll, paginated at 25
    - create and edit happen in a dialog on the same page
    - delete asks for confirmation
- **Delete is blocked** (422 with a message) if the student has participants. The message tells the instructor to remove them from those assessments first. The participants check lands in M05; until then deletion always works.

**Acceptance criteria**

- CRUD works with validation errors shown inline. Search matches part of a name or roll number.

**Tests**

- CRUD feature tests, a duplicate-roll validation test, a search test, and `assertCourseIsolated` for edit, update and delete.

### M02.3 — CSV import with preview, then confirm

**Build**

- `POST students/import/preview` takes a file (csv/txt, ≤ 1 MB). The header row must include `name` and `roll_number` (`email` is optional, column order doesn't matter, matching ignores case).
- `App\Actions\Students\PreviewStudentImport` returns a row-by-row status for every row: `new`, `update` (the roll number exists and the name or email differs), `unchanged`, or `error` (with a reason).
- The preview rows are stored in the session (an import token). They are never written to temporary files.
- `POST students/import/confirm` → `ImportStudents` action, run in one transaction, inserts and updates. It returns counts.
- UI: an "Import CSV" dialog on `students/Index.vue` with the upload, a preview table with colour-coded statuses, then Confirm. Offer a downloadable sample CSV from a static `public/samples/students.csv`.

**Acceptance criteria**

- Importing creates and updates students correctly. Rows with errors are skipped and reported. Re-importing the same file reports every row as `unchanged`.

**Tests**

- Preview classification (all four statuses), a confirm test, a test that a missing header is rejected, and a test that a large file over the limit is rejected.
