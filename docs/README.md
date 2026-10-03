# Evalyst — Spec Docs

Evalyst is an assessment app for programming courses. It runs MCQ and AI-graded quizzes, plus GitHub-project assignments graded by rules. These docs are the spec Claude Code builds from.

## Start here

| Read when                            | File                                                                                                |
| ------------------------------------ | --------------------------------------------------------------------------------------------------- |
| Always, before any task              | [PROGRESS.md](PROGRESS.md): the ordered checklist. Pick the next unchecked task here.               |
| Always, before any task              | The task's section in [milestones/](milestones/)                                                    |
| To understand the product            | [00-product.md](00-product.md): users, glossary, flows, out of scope                                |
| Before writing code                  | [01-architecture.md](01-architecture.md): stack, folders, patterns, course scoping, queues, config  |
| Before any migration or model        | [02-data-model.md](02-data-model.md): **the source of truth** for tables and enums                  |
| Before any AI work                   | [03-ai.md](03-ai.md): agents, schemas, PublishGate, cost logging                                    |
| Before any assignment or GitHub work | [04-github-ingestion.md](04-github-ingestion.md): API-only reads, ignore list, token budget, checks |
| Before writing tests                 | [05-testing.md](05-testing.md)                                                                      |
| Before deploying                     | [06-deployment.md](06-deployment.md)                                                                |
| When something seems odd             | [decisions.md](decisions.md): why things are the way they are                                       |

## Feature specs (behaviour, screens, edge cases)

- [features/instructors-and-courses.md](features/instructors-and-courses.md): courses (Teams), invitations, roster
- [features/question-bank.md](features/question-bank.md)
- [features/ai-question-generation.md](features/ai-question-generation.md)
- [features/quiz-builder-and-access.md](features/quiz-builder-and-access.md)
- [features/quiz-taking.md](features/quiz-taking.md)
- [features/grading-and-review.md](features/grading-and-review.md)
- [features/assignments.md](features/assignments.md)
- [features/results-and-exports.md](features/results-and-exports.md)

## How the docs fit together

- `PROGRESS.md` says **what** to build next, and tracks state. It is the only place with checkboxes.
- `milestones/Mxx-*.md` say **how** to build each task: files, acceptance criteria, tests.
- `features/*.md` say **how the feature behaves**, for users and in edge cases.
- `00`–`06` are the shared rules: architecture, data, AI, GitHub, testing, deployment.
- `decisions.md` records **why**, and logs changes to the plan.

If a spec is wrong or missing something, fix the spec in the same task, add an entry to `decisions.md`, and mention it in your report. Never let the code and the docs drift apart silently.
