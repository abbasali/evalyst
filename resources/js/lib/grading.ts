/** Human labels for gate reasons and AI flags. */
export function reasonLabel(reason: string): string {
    const labels: Record<string, string> = {
        low_confidence: 'Low confidence',
        invalid_output: 'Invalid AI output',
        failed: 'Grading failed',
        'flag:prompt_injection': 'Tried to instruct the AI',
        'flag:off_topic': 'Off topic',
        'flag:possibly_ai_generated': 'Possibly AI-written',
        'flag:blank_or_minimal': 'Blank or minimal',
        'flag:rubric_ambiguous': 'Rubric unclear',
        'flag:repo_mostly_empty': 'Repo mostly empty',
        'flag:unrelated_to_problem': 'Unrelated to the problem',
    };

    return (
        labels[reason] ??
        labels[`flag:${reason}`] ??
        reason.replace(/^flag:/, '').replaceAll('_', ' ')
    );
}

/** 2 → "2", 1.5 → "1.5". */
export function marks(value: number | null | undefined): string {
    if (value === null || value === undefined) {
        return '—';
    }

    return Number.isInteger(value)
        ? String(value)
        : value.toFixed(2).replace(/0$/, '');
}

/** A Markdown code fence that survives backticks inside the code. */
export function codeFence(code: string, language: string | null): string {
    const longest = Math.max(
        2,
        ...(code.match(/`+/g) ?? []).map((run) => run.length),
    );
    const fence = '`'.repeat(longest + 1);

    return `${fence}${language ?? ''}\n${code}\n${fence}`;
}

export function auditActionLabel(action: string): string {
    const labels: Record<string, string> = {
        'grade.accept': 'Accepted the AI grade',
        'grade.bulk_accept': 'Accepted the AI grade (bulk)',
        'grade.override': 'Set the grade',
        'grade.regrade': 'Sent for regrading',
        'question.rubric_update': 'Changed the rubric',
        'attempt.reset': 'Reset the attempt',
        'attempt.allow_resume': 'Allowed resume on another device',
        'attempt.force_submit': 'Submitted the attempt',
        'results.release': 'Released results',
        'results.unrelease': 'Hid results',
        'participant.override': 'Changed deadline or penalty',
        'submission.regrade': 'Regraded the submission',
        'submission.override': 'Changed rule scores',
    };

    return labels[action] ?? action;
}
