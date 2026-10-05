<?php

use App\Enums\AttemptStatus;
use App\Enums\SubmissionStatus;
use App\Models\Assessment;
use App\Models\Attempt;
use App\Models\Participant;
use App\Models\Student;
use App\Models\Submission;
use App\Models\Team;
use Illuminate\Http\UploadedFile;

test('students can be listed and searched', function () {
    [, $team] = actingAsInstructor();
    Student::factory()->for($team)->create(['name' => 'Asha Verma', 'roll_number' => 'CS-001']);
    Student::factory()->for($team)->create(['name' => 'Rahul Mehta', 'roll_number' => 'CS-002']);
    Student::factory()->create(['name' => 'Other Course Asha']);

    $this->get(route('students.index', [$team, 'search' => 'asha']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('students/Index')
            ->has('students.data', 1)
            ->where('students.data.0.roll_number', 'CS-001')
            ->where('total', 2));
});

test('the roster shows each student\'s total graded score', function () {
    [, $team] = actingAsInstructor();
    $quiz = Assessment::factory()->quiz()->published()->for($team)->create();
    $assignment = Assessment::factory()->assignment()->published()->for($team)->create();
    $student = Student::factory()->for($team)->create(['roll_number' => 'CS-001']);
    $other = Student::factory()->for($team)->create(['roll_number' => 'CS-002']);
    Attempt::factory()->for(Participant::factory()->for($quiz)->for($student))->create(['status' => AttemptStatus::Graded, 'score' => 4.5]);
    Submission::factory()->for(Participant::factory()->for($assignment)->for($student))->graded(8)->create();
    Submission::factory()->for(Participant::factory()->for($assignment)->for($other))->create(['status' => SubmissionStatus::NeedsReview, 'score' => 5]);

    $this->get(route('students.index', $team))
        ->assertInertia(fn ($page) => $page
            ->where('students.data.0.total_score', 12.5)
            ->where('students.data.1.total_score', null));
});

test('a student can be added, edited and deleted', function () {
    [, $team] = actingAsInstructor();

    $this->post(route('students.store', $team), ['name' => 'Asha', 'roll_number' => ' cs-001 '])
        ->assertSessionHasNoErrors();

    $student = $team->students()->sole();
    expect($student->roll_number)->toBe('CS-001');

    $this->put(route('students.update', [$team, $student]), ['name' => 'Asha V', 'roll_number' => 'CS-001', 'email' => 'asha@example.com'])
        ->assertSessionHasNoErrors();
    expect($student->fresh()->email)->toBe('asha@example.com');

    $this->delete(route('students.destroy', [$team, $student]))->assertRedirect();
    expect($team->students()->count())->toBe(0);
});

test('roll numbers are unique per course only', function () {
    [, $team] = actingAsInstructor();
    Student::factory()->for($team)->create(['roll_number' => 'CS-001']);
    Student::factory()->create(['roll_number' => 'CS-001']);

    $this->post(route('students.store', $team), ['name' => 'Dup', 'roll_number' => 'cs-001'])
        ->assertSessionHasErrors('roll_number');

    $other = Team::factory()->create();
    expect(Student::where('roll_number', 'CS-001')->count())->toBe(2)
        ->and($other->students()->count())->toBe(0);
});

test('students from another course cannot be touched', function () {
    [, $team] = actingAsInstructor();
    $foreign = Student::factory()->create();

    $this->put(route('students.update', [$team, $foreign]), ['name' => 'X', 'roll_number' => 'X'])->assertNotFound();
    $this->delete(route('students.destroy', [$team, $foreign]))->assertNotFound();
    $this->delete(route('students.destroy', [$foreign->team, $foreign]))->assertForbidden();
});

test('a CSV import is previewed then confirmed', function () {
    [, $team] = actingAsInstructor();
    Student::factory()->for($team)->create(['name' => 'Asha Verma', 'roll_number' => 'CS-001', 'email' => null]);
    Student::factory()->for($team)->create(['name' => 'Old Name', 'roll_number' => 'CS-002', 'email' => null]);

    $csv = "Name,Roll No,Email\nAsha Verma,cs-001,\nNew Name,CS-002,\nNeha,CS-003,neha@example.com\n,CS-004,\nDup,CS-003,\nBad,CS-005,not-an-email\n";
    $file = UploadedFile::fake()->createWithContent('students.csv', $csv);

    $response = $this->post(route('students.import.preview', $team), ['file' => $file])->assertRedirect();
    $preview = $response->getSession()->get('inertia.flash_data.importPreview')
        ?? session()->get('inertia.flash_data.importPreview');
    $rows = collect($preview['rows'] ?? []);

    expect($rows->pluck('status')->all())->toBe(['unchanged', 'update', 'new', 'error', 'error', 'error']);

    $this->post(route('students.import.confirm', $team), ['token' => $preview['token']])->assertRedirect();

    expect($team->students()->count())->toBe(3)
        ->and($team->students()->where('roll_number', 'CS-002')->value('name'))->toBe('New Name');
});

test('a CSV without the required headers is rejected', function () {
    [, $team] = actingAsInstructor();
    $file = UploadedFile::fake()->createWithContent('students.csv', "full,whatever\nA,B\n");

    $this->post(route('students.import.preview', $team), ['file' => $file])
        ->assertSessionHasErrors('file');
});

test('excel-style CSVs are parsed (BOM, quoted headers, CRLF, NBSP, Windows-1252)', function () {
    [, $team] = actingAsInstructor();
    Student::factory()->for($team)->create(['name' => 'Asha', 'roll_number' => 'CS-001', 'email' => null]);

    $csv = "\xEF\xBB\xBF\"Name\",\"Roll No.\",\"Email ID\"\r\n\"Asha\",\"cs-001\u{A0}\",\"\"\r\n\r\n\"Zoë\",\"CS-002\",\"zoe@example.com\"\r\n";
    $cp1252 = mb_convert_encoding("name,roll_number\nRené,CS-003\n", 'Windows-1252', 'UTF-8');

    $preview = function (string $content) use ($team) {
        $this->post(route('students.import.preview', $team), ['file' => UploadedFile::fake()->createWithContent('s.csv', $content)])
            ->assertSessionHasNoErrors();

        return session('inertia.flash_data.importPreview');
    };

    $rows = collect($preview($csv)['rows']);
    expect($rows->pluck('status')->all())->toBe(['unchanged', 'new'])
        ->and($rows->pluck('line')->all())->toBe([2, 4]);

    $result = $preview($cp1252);
    expect($result['rows'][0]['name'])->toBe('René');

    $this->post(route('students.import.confirm', $team), ['token' => $result['token']])->assertSessionHasNoErrors();
    expect($team->students()->where('roll_number', 'CS-003')->value('name'))->toBe('René');
});
