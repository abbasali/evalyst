export type QuestionType =
    | 'single_choice'
    | 'multiple_choice'
    | 'open_text'
    | 'open_code';

export type Option = { value: string; label: string };

export type TagSummary = { id: number; name: string; questions_count?: number };

export type QuestionOption = {
    id?: number;
    body: string;
    is_correct: boolean;
};

export type Question = {
    id: number;
    type: QuestionType;
    type_label: string;
    body: string;
    code_language: string | null;
    default_marks: number;
    scoring_policy: string | null;
    model_answer: string | null;
    rubric: string | null;
    explanation: string | null;
    difficulty: string | null;
    source: 'manual' | 'ai';
    needs_verification: boolean;
    locked: boolean;
    deleted: boolean;
    options: QuestionOption[];
    tags: TagSummary[];
};

export type QuestionRow = Pick<
    Question,
    | 'id'
    | 'type'
    | 'type_label'
    | 'default_marks'
    | 'difficulty'
    | 'source'
    | 'needs_verification'
    | 'locked'
    | 'deleted'
    | 'tags'
> & { excerpt: string };

export type QuestionFormOptions = {
    types: Option[];
    difficulties: Option[];
    scoringPolicies: Option[];
    codeLanguages: Option[];
    defaultCodeLanguage: string;
    tags: TagSummary[];
};

export const isChoiceType = (type: QuestionType): boolean =>
    type === 'single_choice' || type === 'multiple_choice';

export type DraftVerification = {
    status: 'agreed' | 'disputed' | 'not_applicable';
    verifier_selected?: number[];
    confidence?: number;
    reasoning?: string;
};

export type GeneratedDraft = {
    uid: string;
    type: QuestionType;
    body: string;
    code_language: string | null;
    default_marks: number;
    scoring_policy: string | null;
    model_answer: string | null;
    rubric: string | null;
    explanation: string | null;
    difficulty: string | null;
    options: QuestionOption[];
    is_code_output: boolean;
    verification: DraftVerification;
    accepted: boolean;
};
