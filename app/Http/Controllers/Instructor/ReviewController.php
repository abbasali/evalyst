<?php

namespace App\Http\Controllers\Instructor;

use App\Actions\Grading\PublishGrade;
use App\Actions\Grading\RegradeAnswers;
use App\Enums\AnswerGradingStatus;
use App\Enums\AttemptEventType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Instructor\GradeRequest;
use App\Models\Answer;
use App\Models\Assessment;
use App\Models\AssessmentQuestion;
use App\Models\Team;
use App\Queries\ReviewInboxQuery;
use App\Support\AnswerPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The course-wide review inbox: AI grades that need a decision, and failed grading.
 */
class ReviewController extends Controller
{
    private const FILTERS = ['assessment', 'question', 'status', 'reason', 'group'];

    public function index(Team $currentTeam, Request $request): Response
    {
        $filters = $this->filters($request);
        $inbox = new ReviewInboxQuery($currentTeam, $filters);
        $all = (new ReviewInboxQuery($currentTeam))->answers()->reorder();

        $assessmentIds = $all->clone()->join('attempts', 'attempts.id', '=', 'answers.attempt_id')
            ->join('participants', 'participants.id', '=', 'attempts.participant_id')
            ->distinct()->pluck('participants.assessment_id');

        return Inertia::render('review/Index', [
            'rows' => $inbox->rows()->paginate(25)->withQueryString()->through(fn (Answer $answer) => ReviewInboxQuery::row($answer)),
            'filters' => $filters,
            'counts' => [
                'needs_review' => $all->clone()->where('answers.grading_status', AnswerGradingStatus::NeedsReview)->count(),
                'failed' => $all->clone()->where('answers.grading_status', AnswerGradingStatus::Failed)->count(),
            ],
            'assessments' => $currentTeam->assessments()->whereIn('id', $assessmentIds)->orderBy('title')->get(['id', 'title']),
            'questions' => isset($filters['assessment'])
                ? AssessmentQuestion::query()->whereIn('assessment_id', $currentTeam->assessments()->whereKey((int) $filters['assessment'])->select('id'))->with('question:id,body')->orderBy('position')->get()
                    ->map(fn (AssessmentQuestion $item) => ['id' => $item->id, 'label' => "Q{$item->position}. ".Str::limit(Str::squish(strip_tags($item->question->body)), 50)])
                : [],
        ]);
    }

    public function show(Team $currentTeam, Answer $answer, Request $request): Response
    {
        $filters = $this->filters($request);
        $ids = (new ReviewInboxQuery($currentTeam, $filters))->orderedIds();
        $index = array_search($answer->id, $ids, true);

        $attempt = $answer->attempt()->withCount([
            'events as paste_count' => fn ($query) => $query->where('type', AttemptEventType::Pasted),
            'events as fullscreen_exit_count' => fn ($query) => $query->where('type', AttemptEventType::FullscreenExited),
        ])->firstOrFail();
        $participant = $attempt->participant()->with(['student', 'assessment'])->firstOrFail();
        $answer->load(['question.options', 'grader:id,name', 'auditLogs.user:id,name']);

        // Next/previous within the filtered inbox; once decided, "next" is the item after where it was.
        $next = $index === false ? ($ids[0] ?? null) : ($ids[$index + 1] ?? null);
        $previous = $index === false || $index === 0 ? null : $ids[$index - 1];

        return Inertia::render('review/Answer', [
            'answer' => AnswerPresenter::forInstructor($answer, $attempt),
            'student' => ['name' => $participant->student->name, 'roll_number' => $participant->student->roll_number],
            'assessment' => ['id' => $participant->assessment->id, 'title' => $participant->assessment->title],
            'attempt' => [
                'id' => $attempt->id,
                'focus_lost' => $attempt->focus_lost_count,
                'pastes' => (int) $attempt->getAttribute('paste_count'),
                'fullscreen_exits' => (int) $attempt->getAttribute('fullscreen_exit_count'),
            ],
            'position' => $answer->assessmentQuestion()->value('position'),
            'audit' => AnswerPresenter::audit($answer->auditLogs),
            'nav' => [
                'index' => $index === false ? null : $index + 1,
                'total' => count($ids),
                'next' => $next !== $answer->id ? $next : null,
                'previous' => $previous,
            ],
            'filters' => $filters,
        ]);
    }

    public function accept(Team $currentTeam, Answer $answer, Request $request, PublishGrade $publish): RedirectResponse
    {
        if (! $publish->accept($request->user(), $answer)) {
            throw ValidationException::withMessages(['decision' => __('There is no AI grade to accept. Enter a score instead.')]);
        }

        return $this->decided($currentTeam, __('Grade published.'));
    }

    public function update(Team $currentTeam, Answer $answer, GradeRequest $request, PublishGrade $publish): RedirectResponse
    {
        $publish->override($request->user(), $answer, (float) $request->validated('score'), $request->validated('feedback'));

        return $this->decided($currentTeam, __('Grade published.'));
    }

    public function regrade(Team $currentTeam, Answer $answer, Request $request, RegradeAnswers $regrade): RedirectResponse
    {
        if ($regrade->handle($request->user(), [$answer]) === 0) {
            throw ValidationException::withMessages(['decision' => __('This answer can\'t be regraded right now.')]);
        }

        return $this->decided($currentTeam, __('Sent back to the AI for grading.'));
    }

    public function bulkAccept(Team $currentTeam, Request $request, PublishGrade $publish): RedirectResponse
    {
        $request->validate(['ids' => ['required', 'array', 'max:200'], 'ids.*' => ['integer']]);

        $answers = (new ReviewInboxQuery($currentTeam))->answers()->whereIn('answers.id', $request->input('ids'))->get();
        $accepted = $answers->filter(fn (Answer $answer) => $publish->accept($request->user(), $answer, 'grade.bulk_accept'))->count();
        $skipped = count($request->input('ids')) - $accepted;

        ReviewInboxQuery::forgetCount($currentTeam->id);

        return $this->toast('success', $skipped > 0
            ? __('Published :accepted grade(s). Skipped :skipped without an AI grade.', ['accepted' => $accepted, 'skipped' => $skipped])
            : __('Published :accepted grade(s).', ['accepted' => $accepted]));
    }

    /**
     * Regrade every submitted answer to one quiz question (after a rubric or marks change).
     */
    public function regradeQuestion(Team $currentTeam, Assessment $quiz, AssessmentQuestion $assessmentQuestion, Request $request, RegradeAnswers $regrade): RedirectResponse
    {
        $count = $regrade->handle(
            $request->user(),
            Answer::query()->where('assessment_question_id', $assessmentQuestion->id)->with('attempt')->lazyById(),
            keepInstructorGrades: true,
        );

        return $this->decided($currentTeam, trans_choice('{0} Nothing to regrade.|{1} Regrading 1 answer.|[2,*] Regrading :count answers.', $count, ['count' => $count]));
    }

    /**
     * @return array<string, string>
     */
    private function filters(Request $request): array
    {
        return array_filter($request->only(self::FILTERS), fn ($value) => $value !== null && $value !== '');
    }

    private function decided(Team $team, string $message): RedirectResponse
    {
        ReviewInboxQuery::forgetCount($team->id);

        return $this->toast('success', $message);
    }

    private function toast(string $type, string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return back();
    }
}
