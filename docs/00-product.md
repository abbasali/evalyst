# 00 — Product

## What Evalyst is

Evalyst is a web app instructors use to assess students in programming courses, mostly PHP and Laravel. It supports two kinds of assessment:

1. **Quizzes**: timed tests taken in class. They mix auto-graded multiple-choice questions with open-ended text and code questions that AI grades.
2. **Assignments**: projects students work on at home over several days. A student submits a public GitHub repository, and it is graded against rules the instructor defines. Some rules are checked automatically and some are judged by AI. Late submissions follow a late policy that the instructor can override for any student.

Instructors can also generate draft questions with AI, review them, and keep the ones they want in a shared question bank.

## Users

| User           | How they get in                                                                | What they can do                                                                                |
| -------------- | ------------------------------------------------------------------------------ | ----------------------------------------------------------------------------------------------- |
| **Instructor** | An email + password account. Accounts are invite-only, with no public sign-up. | Everything inside the courses they belong to. All instructors in a course have the same powers. |
| **Student**    | No account. They enter an **access code**.                                     | Take a quiz, or submit an assignment, then view their released results.                         |

## Glossary

| Term                    | Meaning                                                                                                                                                              | Code name                                                              |
| ----------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------- |
| **Course**              | A class being taught. It owns everything else: question bank, students, assessments. Several instructors can share one course.                                       | `Team` (from the starter kit). In the UI it is always called "Course". |
| **Instructor**          | A user who belongs to a course.                                                                                                                                      | `User` + `Membership`                                                  |
| **Student**             | A person on a course's roster, identified by name and roll number.                                                                                                   | `Student`                                                              |
| **Question bank**       | All questions in a course.                                                                                                                                           | `Question`, `QuestionOption`, `Tag`                                    |
| **Question generation** | One AI run that produces draft questions from a prompt.                                                                                                              | `QuestionGeneration`                                                   |
| **Assessment**          | Either a quiz or an assignment.                                                                                                                                      | `Assessment` with `type` = `quiz` or `assignment`                      |
| **Participant**         | A student's entry in one assessment. It holds the access code and per-student overrides.                                                                             | `Participant`                                                          |
| **Access mode**         | **Roster**: each participant gets their own unique code. **Shared code**: one code for the whole assessment, and the student also enters their name and roll number. | `AccessMode` enum                                                      |
| **Attempt**             | A participant's single sitting of a quiz.                                                                                                                            | `Attempt`                                                              |
| **Answer**              | A participant's response to one quiz question.                                                                                                                       | `Answer`                                                               |
| **Rule**                | One grading criterion for an assignment. It is either _automated_ (checked in code) or _AI_ (judged by the model).                                                   | `AssignmentRule`                                                       |
| **Submission**          | A participant's repo URL + the commit SHA recorded at the moment they submit.                                                                                        | `Submission`                                                           |
| **AI run**              | One call to an AI model, logged with token counts and cost.                                                                                                          | `AiRun`                                                                |
| **Review inbox**        | Every AI-graded item that still needs an instructor's decision.                                                                                                      | —                                                                      |
| **Publish**             | Make a grade final and visible to the student, once results are released.                                                                                            | `published_at`                                                         |

## Question types

| Type              | Student input                                                          | How it is graded                                                                                             |
| ----------------- | ---------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------ |
| `single_choice`   | Radio buttons                                                          | Automatically. Exactly one option is correct.                                                                |
| `multiple_choice` | Checkboxes                                                             | Automatically, using the question's scoring policy (`all_or_nothing`, `partial`, or `partial_with_penalty`). |
| `open_text`       | Plain textarea                                                         | AI grades against the model answer and rubric.                                                               |
| `open_code`       | A textarea for the explanation + a code editor for the chosen language | AI grades both the explanation and the code against the model answer and rubric.                             |

Assignments do not use questions. They have a **problem statement** and **rules** instead.

## Main flows

### Instructor: quiz

1. Create a course (or accept an invitation to one). Add students to the roster.
2. Build questions by hand, or generate them with AI and choose which to keep.
3. Create a quiz: settings, questions and marks, access mode. Publish it.
4. Students take it. The instructor watches progress live.
5. After submission, multiple-choice answers are scored instantly. Open answers go to the AI queue:
    - A _confident_ AI grade is published automatically.
    - Anything else goes to the **review inbox**.
6. Release results, either manually or automatically.

### Instructor: assignment

1. Create an assignment with a problem statement, start date, deadline, late policy and rules.
2. Publish it. Students submit repo URLs from home.
3. Each submission is graded in the background: the repo is read through the GitHub API (it is never cloned), automated rules run, AI rules are judged, and any late penalty is applied.
4. Overrides for individual students are allowed at any time, before or after grading: extend the deadline, allow late submission, waive the penalty. The final score is recalculated whenever an override changes.

### Student: quiz

1. Go to `/join` and enter a code. In shared-code mode they also enter their name and roll number.
2. Read the instructions and start. The timer starts.
3. Answer one question per screen. Previous/Next buttons and a question map let them jump to any question and flag questions to come back to. Answers autosave.
4. Submit, or the quiz submits automatically when time runs out. The confirmation page gives a **results link**.

### Student: assignment

1. Enter a code to open the assignment page: problem statement, visible rules, deadline countdown, and the late policy.
2. Submit a public GitHub repo URL. Resubmitting before the deadline is allowed if the instructor enabled it.
3. View results through the results link once they are released.

## Out of scope for v1

- Running student code or test suites.
- Private repositories.
- Student accounts, logins, or emails to students.
- Proctoring beyond logging focus loss.
- Question types beyond the four above.
- Using the Jev model (see `decisions.md`).
