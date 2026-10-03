# M03 — Question bank

**Goal:** instructors can create, browse, tag, preview and maintain questions of all four types in their course's bank. Questions lock once students have answered them.

**Depends on:** M01 (scoping), M02 is not required.
**Read:** `features/question-bank.md`, `02-data-model.md` §questions/question_options/tags, `decisions.md` D-009.

---

### M03.1 — Enums, migrations, models, factories

**Build**

- Enums in `app/Enums`: `QuestionType`, `ChoiceScoringPolicy`, `Difficulty`, `QuestionSource`, `CodeLanguage`, each with `label()`. `QuestionType` also gets `isChoice()` and `isOpen()`.
- Migrations: `tags`, `questions`, `question_options`, `question_tag`, exactly as in `02-data-model.md`. Leave out the `question_generation_id` FK column for now. M04.4 adds it.
- Models:
    - `Question` (`BelongsToCourse`, `SoftDeletes`, enum casts, `options()` ordered by position, `tags()`, `creator()`, `isLocked()`)
    - `QuestionOption`
    - `Tag` (`BelongsToCourse`)
- Factories with states: `singleChoice()`, `multipleChoice()`, `openText()`, `openCode()`, `aiGenerated()`, `locked()`. The choice states create valid options.

**Acceptance criteria**

- Migrations run and roll back. Factories produce valid questions for each type.

**Tests**

- Unit: enum helpers. Factory sanity checks (single choice has exactly 1 correct option, multiple choice has at least 2).

### M03.2 — `<Markdown>` component

**Goal:** one safe renderer for every question, option and statement body, used by both instructors and students.

**Build**

- `npm i markdown-it dompurify highlight.js @types/markdown-it` (approved in `01-architecture.md`).
- `resources/js/lib/markdown.ts`:
    - markdown-it with `html: false` and `linkify: true`
    - highlight.js **core** with only these languages registered: php, javascript, typescript, sql, bash, xml (also used for html/blade), css, json
    - output passed through DOMPurify
    - links get `target="_blank" rel="noopener"`
- `resources/js/components/markdown/Markdown.vue` (`source: string`, `inline?: boolean`). Code blocks are styled to work in light and dark mode (Tailwind `prose` is not installed, so add minimal CSS in `resources/css/app.css` under `.md`).
- `components/markdown/MarkdownEditor.vue`: a textarea with Write/Preview tabs, used by every Markdown field.

**Acceptance criteria**

- `<script>` and `onerror=` payloads are stripped. PHP code fences are highlighted. The bundle doesn't pull in every highlight.js language (check the build output).

**Tests**

- Pest can't test Vue directly. Cover this with `npm run types:check`, and record a manual check in the PR/task note. Add a small XSS fixture page only if Pest browser testing is set up later.

### M03.3 — Question index

**Build**

- `routes/instructor.php`: `Route::resource('questions', Instructor\QuestionController::class)`.
- `index`:
    - filters for `type`, `tag` (multi), `difficulty`, `source`, `needs_verification`, `q` (searches body text)
    - paginated at 20, sorted by newest
    - eager-loads options and tags (counts only)
- `pages/questions/Index.vue`:
    - filter bar, kept in the query string
    - each row shows: a type badge, the first ~120 characters of the body as plain text, tags, marks, difficulty, an "AI" badge, a "Verify" warning badge when `needs_verification`, and a lock icon
    - row actions: Preview, Edit, Duplicate, Delete
    - header buttons: "New question" and "Generate with AI" (the second is a disabled placeholder until M04)

**Acceptance criteria**

- Filters can be combined. Pagination keeps the filters. There are no N+1 queries: assert the query count in the test, or use `preventLazyLoading` in tests.

**Tests**

- Filter combinations, search, isolation (questions from another course never appear).

### M03.4 — Create/edit form for all 4 types

**Build**

- `StoreQuestionRequest` / `UpdateQuestionRequest` with rules that depend on the type:
    - all types: `body` required, `default_marks` numeric between 0.5 and 100, `difficulty` nullable, `tag_ids` must exist in the course, `explanation` nullable
    - `single_choice`: 2–8 options, **exactly 1** correct
    - `multiple_choice`: 2–8 options, **at least 1** correct (usually 2 or more, with a UI hint), `scoring_policy` required
    - `open_text`: `model_answer` required, `rubric` recommended (nullable)
    - `open_code`: `model_answer` required, `code_language` required
- Actions: `CreateQuestion` and `UpdateQuestion` (options synced by position inside a transaction). Updating a **locked** question returns 403 (enforced in the policy `update`).
- `pages/questions/Form.vue` (shared by create and edit):
    - a type selector (cannot change once options exist on edit; warn instead)
    - `MarkdownEditor` for the body
    - an options editor: add, remove, reorder (up/down buttons) and mark correct (radio or checkbox depending on type)
    - marks, policy, model answer, rubric, explanation, difficulty and a tags multi-select

**Acceptance criteria**

- Each type's validation rules are enforced on the server and shown inline.
- Edits keep the option IDs where possible, updating rows by position rather than deleting and recreating them.

**Tests**

- A dataset-driven validation test covering every rule. Create and update for each type. Updating a locked question gives 403. Isolation.

### M03.5 — Question preview

**Build**

- `components/questions/QuestionView.vue` renders a question exactly as students will see it: the body, then radio or checkbox options, or a textarea plus code editor area (a plain `<pre>` placeholder until M06.4 brings CodeMirror). It has a `mode: 'preview' | 'answer' | 'review'` prop, so M06 and M08 reuse it.
- A preview dialog opened from the index and form pages. It can optionally show the answer key (a toggle).

**Acceptance criteria**

- The preview matches the student rendering. The correct answer is hidden unless the toggle is on.

### M03.6 — Tags

**Build**

- `Route::resource('tags', ...)->only(['index','store','update','destroy'])`.
- Tags can be created inline from the tags multi-select (`POST tags`, returns JSON for a `useHttp`/fetch call, or an Inertia partial reload).
- A bulk action on the questions index: select rows, then "Add tag" or "Remove tag" (`POST questions/bulk-tag`).
- Tag management page `pages/tags/Index.vue`: rename and delete. Deleting a tag only detaches it from questions.

**Acceptance criteria**

- Tag names are unique per course, ignoring case. Bulk tagging works across pages of selected IDs, and the IDs are validated against the course.

**Tests**

- Uniqueness, bulk tag/untag, isolation (tag IDs from another course are rejected).

### M03.7 — Locking, duplicate, soft delete

**Build**

- `locked_at` is set by M06.5 the first time an answer is recorded. For now, add `Question::lock()` and use the `locked()` factory state in tests.
- `POST questions/{question}/duplicate` → `DuplicateQuestion` action: copies the body, options, tags and fields. The copy has `source = manual`, `locked_at = null`, `needs_verification` copied, and " (copy)" is **not** added to the body. It redirects to edit the copy.
- Delete is a soft delete. It is blocked (422) when the question is attached to a published assessment. That check activates in M05, when the relation exists. Soft-deleted questions stay visible inside past attempts.
- In the UI, a locked question's Edit button becomes "Duplicate to edit", with an explanatory tooltip.

**Acceptance criteria**

- A locked question can't be edited but can be duplicated. The duplicate can be edited.

**Tests**

- The duplicate copies everything that matters. Locked behaviour. Soft-delete visibility.
