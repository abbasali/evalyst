import type { AccessMode, PublishCheck, QuizState } from './quizzes';

export type AssignmentSummary = {
    id: number;
    public_id: string;
    title: string;
    state: QuizState;
    access_mode: AccessMode;
    opens_at: string | null;
    closes_at: string;
    rules_count: number;
    max_score: number;
    participants_count: number;
    has_submissions: boolean;
    can: {
        update: boolean;
        publish: boolean;
        unpublish: boolean;
        archive: boolean;
        unarchive: boolean;
        delete: boolean;
    };
};

/** Props every assignment tab page receives for the shell. */
export type AssignmentShellProps = {
    assignment: AssignmentSummary;
    checklist: PublishCheck[];
};

export type AssignmentRow = {
    id: number;
    title: string;
    state: QuizState;
    access_mode: AccessMode;
    opens_at: string | null;
    closes_at: string;
    rules_count: number;
    max_score: number;
    participants_count: number;
    submitted_count: number;
};

export type RuleKind = 'automated' | 'ai';

export type AutomatedCheck =
    | 'min_commits'
    | 'min_commit_days'
    | 'commit_message_pattern'
    | 'path_exists'
    | 'path_absent'
    | 'file_contains';

export type RuleConfig = {
    min?: number | string;
    pattern?: string;
    min_ratio?: number | string;
    glob?: string;
    min_matches?: number | string;
};

export type AssignmentRuleForm = {
    id: number | null;
    key: string;
    kind: RuleKind;
    title: string;
    description: string | null;
    check: AutomatedCheck | null;
    config: RuleConfig;
    marks: number | string;
    has_results?: boolean;
};

export type ParticipantOverrides = {
    deadline_override_at: string;
    late_override: string;
    penalty_waived: boolean;
    penalty_override: number | '';
    override_note: string;
};
