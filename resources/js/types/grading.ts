export type GradingStatus =
    | 'ungraded'
    | 'pending'
    | 'needs_review'
    | 'failed'
    | 'final';

export type AnswerOption = {
    body: string;
    selected: boolean;
    correct: boolean | null;
};

export type AiSuggestion = {
    score: number | null;
    feedback: string | null;
    confidence: number | null;
    breakdown: {
        criterion: string;
        awarded: number;
        max: number;
        note: string;
    }[];
    flags: string[];
    reasons: string[];
    error: string | null;
};

export type InstructorAnswer = {
    id: number;
    question: {
        id: number;
        type: 'single_choice' | 'multiple_choice' | 'open_text' | 'open_code';
        type_label: string;
        body: string;
        code_language: string | null;
        model_answer: string | null;
        rubric: string | null;
        explanation: string | null;
        scoring_policy: string | null;
    };
    options: AnswerOption[];
    text_answer: string | null;
    code_answer: string | null;
    is_blank: boolean;
    status: GradingStatus;
    score: number | null;
    max_score: number;
    feedback: string | null;
    published_at: string | null;
    graded_by: string | null;
    ai: AiSuggestion | null;
};

export type AuditEntry = {
    id: number;
    action: string;
    user: string | null;
    created_at: string | null;
    before: Record<string, unknown> | null;
    after: Record<string, unknown> | null;
    note: string | null;
};

export type ReviewRow = {
    kind: 'answer' | 'submission';
    id: number;
    assessment: { id: number; title: string };
    student: { name: string; roll_number: string };
    question: { position: number; type: string; excerpt: string };
    status: 'needs_review' | 'failed';
    ai_score: number | null;
    max_score: number;
    confidence: number | null;
    reasons: string[];
    published_score: number | null;
    waiting_since: string | null;
};

export type ReviewFilters = {
    assessment?: string;
    question?: string;
    status?: string;
    reason?: string;
    group?: string;
};
