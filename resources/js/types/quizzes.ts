import type { QuestionType, TagSummary } from './questions';

export type QuizState = 'draft' | 'upcoming' | 'open' | 'closed' | 'archived';

export type AccessMode = 'roster' | 'shared_code';

export type QuizSummary = {
    id: number;
    public_id: string;
    title: string;
    state: QuizState;
    access_mode: AccessMode;
    opens_at: string | null;
    closes_at: string;
    duration_minutes: number | null;
    questions_count: number;
    max_score: number;
    participants_count: number;
    has_attempts: boolean;
    can: {
        update: boolean;
        edit_questions: boolean;
        publish: boolean;
        unpublish: boolean;
        archive: boolean;
        unarchive: boolean;
        delete: boolean;
    };
};

export type PublishCheck = { key: string; label: string; ok: boolean };

/** Props every quiz tab page receives for the shell. */
export type QuizShellProps = {
    quiz: QuizSummary;
    checklist: PublishCheck[];
};

export type QuizRow = {
    id: number;
    title: string;
    state: QuizState;
    access_mode: AccessMode;
    opens_at: string | null;
    closes_at: string;
    duration_minutes: number | null;
    questions_count: number;
    max_score: number;
    participants_count: number;
    started_count: number;
    submitted_count: number;
};

export type QuizSettingsForm = {
    title: string;
    instructions: string;
    opens_at: string;
    closes_at: string;
    duration_minutes: number | '';
    shuffle_questions: boolean;
    shuffle_options: boolean;
    show_answers_after_release: boolean;
    track_focus: boolean;
    one_way_navigation: boolean;
    require_fullscreen: boolean;
    release_mode: 'manual' | 'automatic';
    auto_publish_threshold: number | '';
    access_mode: AccessMode;
};

export type BankQuestion = {
    id: number;
    type: QuestionType;
    type_label: string;
    excerpt: string;
    default_marks: number;
    difficulty: string | null;
    needs_verification: boolean;
    tags: TagSummary[];
    added?: boolean;
    deleted?: boolean;
};

export type QuizQuestionItem = {
    id: number;
    position: number;
    marks: number;
    question: BankQuestion;
};

export type ParticipantStatus = 'not_started' | 'in_progress' | 'submitted';

export type ParticipantRow = {
    id: number;
    student_id: number;
    name: string;
    roll_number: string;
    access_code: string | null;
    status: ParticipantStatus;
    joined_at: string | null;
};

export type RosterStudent = { id: number; name: string; roll_number: string };
