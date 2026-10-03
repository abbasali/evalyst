<?php

namespace App\Actions\Students;

use App\Models\Student;
use App\Models\Team;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PreviewStudentImport
{
    public const MAX_ROWS = 2000;

    /**
     * Accepted header names for each column, after normalising to snake_case.
     *
     * @var array<string, list<string>>
     */
    private const HEADERS = [
        'name' => ['name', 'student_name', 'full_name', 'student'],
        'roll_number' => ['roll_number', 'roll', 'roll_no', 'rollno', 'roll_num', 'enrollment_no', 'enrolment_no'],
        'email' => ['email', 'email_id', 'e_mail', 'email_address', 'student_email'],
    ];

    /**
     * Classify every CSV row as new, update, unchanged or error.
     *
     * @return list<array{line: int, name: string, roll_number: string, email: string|null, status: string, error: string|null}>
     */
    public function handle(Team $team, UploadedFile $file): array
    {
        $handle = $this->utf8Stream($file);

        $columns = $this->mapHeader(fgetcsv($handle, null, ',', '"', '') ?: []);

        $existing = $team->students()->get()->keyBy('roll_number');
        $seen = [];
        $rows = [];
        $line = 1;

        while (($values = fgetcsv($handle, null, ',', '"', '')) !== false) {
            $line++;

            if (Str::trim(implode('', array_map('strval', $values))) === '') {
                continue;
            }

            if (count($rows) >= self::MAX_ROWS) {
                throw ValidationException::withMessages(['file' => __('The file has more than :max rows.', ['max' => self::MAX_ROWS])]);
            }

            $email = isset($columns['email']) ? Str::trim((string) ($values[$columns['email']] ?? '')) : '';

            $row = [
                'line' => $line,
                'name' => Str::squish((string) ($values[$columns['name']] ?? '')),
                'roll_number' => Student::normalizeRollNumber((string) ($values[$columns['roll_number']] ?? '')),
                'email' => $email === '' ? null : mb_strtolower($email),
                'status' => 'new',
                'error' => null,
            ];
            $row['error'] = $this->rowError($row, $seen);

            if ($row['error']) {
                $row['status'] = 'error';
            } else {
                $seen[$row['roll_number']] = true;
                $student = $existing->get($row['roll_number']);
                $row['status'] = match (true) {
                    $student === null => 'new',
                    $student->name === $row['name'] && $student->email === $row['email'] => 'unchanged',
                    default => 'update',
                };
            }

            $rows[] = $row;
        }

        fclose($handle);

        if ($rows === []) {
            throw ValidationException::withMessages(['file' => __('The file has no student rows.')]);
        }

        return $rows;
    }

    /**
     * Read the upload as UTF-8 (Excel's Windows-1252 CSVs are converted) without a BOM.
     *
     * @return resource
     */
    private function utf8Stream(UploadedFile $file)
    {
        $raw = (string) file_get_contents($file->getRealPath());

        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        }

        if (! mb_check_encoding($raw, 'UTF-8')) {
            $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
        }

        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            throw new \RuntimeException('Unable to open a temporary stream for the CSV import.');
        }

        fwrite($handle, $raw);
        rewind($handle);

        return $handle;
    }

    /**
     * @param  array<int, string|null>  $header
     * @return array{name: int, roll_number: int, email?: int}
     */
    private function mapHeader(array $header): array
    {
        $normalized = array_map(
            fn ($value) => trim((string) preg_replace('/[^a-z0-9]+/', '_', mb_strtolower(Str::trim((string) $value))), '_'),
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
