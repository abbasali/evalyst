export type Student = {
    id: number;
    name: string;
    roll_number: string;
    email: string | null;
    total_score?: number | null;
};

export type StudentImportRow = {
    line: number;
    name: string;
    roll_number: string;
    email: string | null;
    status: 'new' | 'update' | 'unchanged' | 'error';
    error: string | null;
};
