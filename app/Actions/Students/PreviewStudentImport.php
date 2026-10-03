<?php

namespace App\Actions\Students;

use App\Models\Student;
use App\Models\Team;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use SplFileObject;

class PreviewStudentImport
{
    public const MAX_ROWS = 2000;

    /**
     * Accepted header names for each column (compared lowercased, spaces → underscores).
     *
     * @var array<string, list<string>>
     */
    private const HEADERS = [
        'name' => ['name', 'student_name', 'full_name'],
        'roll_number' => ['roll_number', 'roll', 'roll_no', 'rollno'],
        'email' => ['email', 'email_address'],
    ];

    /**
     * Classify every CSV row as new, update, unchanged or error.
     *
     * @return list<array{line: int, name: string, roll_number: string, email: string|null, status: string, error: string|null}>
     */
    public function handle(Team $team, UploadedFile $file): array
    {
        $csv = new SplFileObject($file->getRealPath());
        $csv->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::READ_AHEAD | SplFileObject::DROP_NEW_LINE);

        $columns = $this->mapHeader($csv->fgetcsv() ?: []);

        $existing = $team->students()->get()->keyBy('roll_number');
        $seen = [];
        $rows = [];
        $line = 1;

        while (! $csv->eof()) {
            $values = $csv->fgetcsv();
            $line++;

            if (! is_array($values) || $values === [null] || implode('', array_map('strval', $values)) === '') {
                continue;
            }

            if (count($rows) >= self::MAX_ROWS) {
                throw ValidationException::withMessages(['file' => __('The file has more than :max rows.', ['max' => self::MAX_ROWS])]);
            }

            $name = trim((string) ($values[$columns['name']] ?? ''));
            $roll = Student::normalizeRollNumber((string) ($values[$columns['roll_number']] ?? ''));
            $email = isset($columns['email']) ? trim((string) ($values[$columns['email']] ?? '')) : '';
            $email = $email === '' ? null : $email;

            $row = ['line' => $line, 'name' => $name, 'roll_number' => $roll, 'email' => $email, 'status' => 'new', 'error' => null];
            $row['error'] = $this->rowError($row, $seen);

            if ($row['error']) {
                $row['status'] = 'error';
            } elseif ($student = $existing->get($roll)) {
                $row['status'] = $student->name === $name && $student->email === $email ? 'unchanged' : 'update';
            }

            if ($roll !== '') {
                $seen[$roll] = true;
            }

            $rows[] = $row;
        }

        if ($rows === []) {
            throw ValidationException::withMessages(['file' => __('The file has no student rows.')]);
        }

        return $rows;
    }

    /**
     * @param  array<int, string|null>  $header
     * @return array{name: int, roll_number: int, email?: int}
     */
    private function mapHeader(array $header): array
    {
        $normalized = array_map(
            fn ($value) => str_replace([' ', '-', '.'], '_', strtolower(trim((string) $value, " \t\n\r\0\x0B\u{FEFF}"))),
            $header,
        );

        $columns = [];

        foreach (self::HEADERS as $column => $aliases) {
            foreach ($normalized as $index => $value) {
                if (in_array($value, $aliases, true)) {
                    $columns[$column] = $index;
                    break;
                }
            }
        }

        if (! isset($columns['name'], $columns['roll_number'])) {
            throw ValidationException::withMessages(['file' => __('The first row must contain "name" and "roll_number" column headers.')]);
        }

        return $columns;
    }

    /**
     * @param  array{name: string, roll_number: string, email: string|null}  $row
     * @param  array<string, bool>  $seen
     */
    private function rowError(array $row, array $seen): ?string
    {
        if ($row['name'] === '' || $row['roll_number'] === '') {
            return __('Name and roll number are required.');
        }

        if (isset($seen[$row['roll_number']])) {
            return __('Duplicate roll number in this file.');
        }

        $validator = Validator::make($row, [
            'name' => 'max:255',
            'roll_number' => 'max:50',
            'email' => 'nullable|email|max:255',
        ]);

        return $validator->fails() ? $validator->errors()->first() : null;
    }
}
