<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Cohort;
use App\Models\CohortEnrolment;
use App\Models\Experience;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * End-to-end data integrity tests.
 *
 * Verifies that data passes through the full request lifecycle — from JSON
 * input, through validation and service layers, into the database, back out
 * via JSON responses, and into CSV exports — without any corruption,
 * mutation, truncation, or encoding loss.
 *
 * Each test case uses deliberately tricky input: Unicode, math operators,
 * HTML-like strings, quotes, commas, newlines, formula-prefix characters,
 * and emoji. If any layer silently transforms data, these tests catch it.
 */
class DataIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private Experience $experience;
    private Cohort $cohort;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::create([
            'name' => 'Ridgewood Academy',
            'code' => 'RIDGE',
            'is_active' => true,
        ]);

        // ID 1 → test-admin-token
        User::create([
            'name' => 'Admin',
            'email' => 'admin@ridgewood.edu',
            'password' => bcrypt('password'),
            'role' => 'school_admin',
            'school_id' => $this->school->id,
        ]);

        // ID 2 → test-teacher-token
        User::create([
            'name' => 'Teacher',
            'email' => 'teacher@ridgewood.edu',
            'password' => bcrypt('password'),
            'role' => 'school_teacher',
            'school_id' => $this->school->id,
        ]);

        $this->experience = Experience::create([
            'school_id' => $this->school->id,
            'name' => 'Test Experience',
            'description' => 'For data integrity testing',
            'status' => 'active',
            'created_by' => 1,
        ]);

        $this->cohort = Cohort::create([
            'experience_id' => $this->experience->id,
            'school_id' => $this->school->id,
            'name' => 'Test Cohort',
            'status' => 'active',
            'capacity' => 50,
            'start_date' => '2026-02-01',
            'end_date' => '2026-06-01',
        ]);
    }

    private function teacherHeaders(): array
    {
        return ['Authorization' => 'Bearer test-teacher-token'];
    }

    private function adminHeaders(): array
    {
        return ['Authorization' => 'Bearer test-admin-token'];
    }

    // ── Cohort name: API → DB → JSON round-trip ─────────────

    /**
     * Test data provider: strings that historically cause problems with
     * sanitizers, encoders, or formatters.
     */
    private function trickyStrings(): array
    {
        return [
            'math_less_than'    => 'Grade 3 < Grade 4',
            'math_expression'   => '2<3 and 5>2',
            'html_tags'         => '<b>Bold</b> & <em>italic</em>',
            'script_tag'        => '<script>alert("xss")</script>',
            'ampersand'         => 'Art & Design',
            'html_entities'     => '&amp; &lt; &gt; &quot;',
            'single_quotes'     => "O'Brien's Class",
            'double_quotes'     => 'The "Advanced" Group',
            'mixed_quotes'      => "She said \"it's fine\"",
            'unicode'           => "Ñoño's Café — résumé",
            'cjk'              => '日本語テスト Chinese: 中文',
            'emoji'             => 'Graduation 🎓 Class 🏫',
            'comma'             => 'Smith, John - Room 101',
            'newline'           => "Line 1\nLine 2",
            'tab'               => "Col1\tCol2",
            'backslash'         => 'Path: C:\\Users\\test',
            'formula_equals'    => '=SUM(A1:A10)',
            'formula_plus'      => '+1-555-0123',
            'formula_minus'     => '-Jane Doe',
            'formula_at'        => '@admin_user',
            // Note: leading/trailing whitespace is intentionally trimmed by
            // Laravel's TrimStrings middleware — this is standard behavior.
            'percent'           => '100% Complete',
            'parentheses'       => 'Group (A) - Section [B]',
            'pipe'              => 'Option A | Option B',
            'hash'              => 'Issue #42',
            'dollar'            => 'Cost: $50.00',
        ];
    }

    /**
     * Create a cohort with each tricky string as its name, then verify
     * the exact string is stored in the DB and returned in the JSON response.
     */
    public function test_cohort_names_survive_api_to_db_round_trip(): void
    {
        foreach ($this->trickyStrings() as $label => $input) {
            $response = $this->postJson('/api/school/cohorts', [
                'experience_id' => $this->experience->id,
                'name' => $input,
                'start_date' => '2026-04-01',
                'end_date' => '2026-08-01',
            ], $this->teacherHeaders());

            $response->assertStatus(201, "Failed to create cohort for case: {$label}");

            $id = $response->json('id');

            // Verify JSON response contains exact input
            $this->assertSame(
                $input,
                $response->json('name'),
                "JSON response mismatch for case: {$label}"
            );

            // Verify database contains exact input
            $dbValue = Cohort::withoutGlobalScopes()->find($id)->getRawOriginal('name');
            $this->assertSame(
                $input,
                $dbValue,
                "Database value mismatch for case: {$label}"
            );

            // Verify GET endpoint returns exact input
            $getResponse = $this->getJson("/api/school/cohorts/{$id}", $this->teacherHeaders());
            $getResponse->assertStatus(200);
            $this->assertSame(
                $input,
                $getResponse->json('name'),
                "GET response mismatch for case: {$label}"
            );
        }
    }

    // ── CSV export: DB → CSV round-trip ─────────────────────

    /**
     * Create students with tricky names and emails, enrol them, export CSV,
     * parse the CSV, and verify every value matches what was stored.
     */
    public function test_csv_export_preserves_all_data_verbatim(): void
    {
        $testCases = [
            ['name' => "O'Brien, James",     'email' => 'obrien@school.edu'],
            ['name' => 'Ñoño García',        'email' => 'nono@school.edu'],
            ['name' => '=SUM(A1:A10)',       'email' => '+1test@school.edu'],
            ['name' => 'Line 1 "quoted"',    'email' => 'quoted@school.edu'],
            ['name' => '<b>Bold Student</b>','email' => 'bold@school.edu'],
            ['name' => 'Smith & Wesson',     'email' => 'smith@school.edu'],
            ['name' => 'Tab\there',          'email' => 'tab@school.edu'],
            ['name' => '-Negative Name',     'email' => '-neg@school.edu'],
            ['name' => '@At Student',        'email' => '@at@school.edu'],
            ['name' => 'Cost: $50',          'email' => 'cost@school.edu'],
            ['name' => '日本語 Student',     'email' => 'jp@school.edu'],
            ['name' => 'Emoji 🎓',           'email' => 'emoji@school.edu'],
        ];

        // Create filler user for ID 3, students start at ID 4+
        User::create([
            'name' => 'Filler',
            'email' => 'filler@school.edu',
            'password' => bcrypt('p'),
            'role' => 'school_teacher',
            'school_id' => $this->school->id,
        ]);

        $expectedRows = [];
        foreach ($testCases as $i => $case) {
            $student = User::create([
                'name' => $case['name'],
                'email' => $case['email'],
                'password' => bcrypt('password'),
                'role' => 'student',
                'school_id' => $this->school->id,
            ]);

            CohortEnrolment::create([
                'cohort_id' => $this->cohort->id,
                'student_id' => $student->id,
                'status' => 'enrolled',
                'enrolled_at' => now(),
            ]);

            $expectedRows[] = [
                'student_name' => $case['name'],
                'student_email' => $case['email'],
                'cohort_name' => 'Test Cohort',
                'experience_name' => 'Test Experience',
                'status' => 'enrolled',
            ];
        }

        $response = $this->get('/api/school/enrolments/export', $this->adminHeaders());
        $response->assertStatus(200);

        $content = $response->streamedContent();
        $lines = explode("\n", trim($content));
        $headers = str_getcsv($lines[0]);

        // Parse all data rows
        $csvRows = [];
        for ($i = 1; $i < count($lines); $i++) {
            $values = str_getcsv($lines[$i]);
            if (count($values) === count($headers)) {
                $csvRows[] = array_combine($headers, $values);
            }
        }

        // Verify each expected row exists in the CSV with exact values
        foreach ($expectedRows as $j => $expected) {
            $found = false;
            foreach ($csvRows as $csvRow) {
                if ($csvRow['student_email'] === $expected['student_email']) {
                    $found = true;
                    $this->assertSame(
                        $expected['student_name'],
                        $csvRow['student_name'],
                        "CSV name mismatch for: {$expected['student_email']}"
                    );
                    $this->assertSame(
                        $expected['cohort_name'],
                        $csvRow['cohort_name'],
                        "CSV cohort mismatch for: {$expected['student_email']}"
                    );
                    $this->assertSame(
                        $expected['experience_name'],
                        $csvRow['experience_name'],
                        "CSV experience mismatch for: {$expected['student_email']}"
                    );
                    $this->assertSame(
                        $expected['status'],
                        $csvRow['status'],
                        "CSV status mismatch for: {$expected['student_email']}"
                    );
                    break;
                }
            }
            $this->assertTrue($found, "Student not found in CSV: {$expected['student_email']}");
        }
    }

    /**
     * Verify that a cohort with a tricky name appears correctly in CSV export
     * when a student is enrolled in it. Tests the full path:
     * cohort name → DB → enrolment join → CSV serialization.
     */
    public function test_csv_export_preserves_cohort_names_with_special_chars(): void
    {
        $trickyName = 'Cohort <A> "Advanced" — O\'Brien\'s & Co. 🎓';

        $trickyCohort = Cohort::create([
            'experience_id' => $this->experience->id,
            'school_id' => $this->school->id,
            'name' => $trickyName,
            'status' => 'active',
            'capacity' => 10,
            'start_date' => '2026-04-01',
            'end_date' => '2026-08-01',
        ]);

        // Create student at ID 3+
        User::create([
            'name' => 'Filler',
            'email' => 'filler@test.edu',
            'password' => bcrypt('p'),
            'role' => 'school_teacher',
            'school_id' => $this->school->id,
        ]);
        $student = User::create([
            'name' => 'Test Student',
            'email' => 'test@test.edu',
            'password' => bcrypt('p'),
            'role' => 'student',
            'school_id' => $this->school->id,
        ]);

        CohortEnrolment::create([
            'cohort_id' => $trickyCohort->id,
            'student_id' => $student->id,
            'status' => 'enrolled',
            'enrolled_at' => now(),
        ]);

        $response = $this->get('/api/school/enrolments/export', $this->adminHeaders());
        $response->assertStatus(200);

        $content = $response->streamedContent();
        $lines = explode("\n", trim($content));
        $headers = str_getcsv($lines[0]);

        // Find the row for our student
        for ($i = 1; $i < count($lines); $i++) {
            $values = str_getcsv($lines[$i]);
            if (count($values) === count($headers)) {
                $row = array_combine($headers, $values);
                if ($row['student_email'] === 'test@test.edu') {
                    $this->assertSame(
                        $trickyName,
                        $row['cohort_name'],
                        'Cohort name with special characters was corrupted in CSV export'
                    );
                    return;
                }
            }
        }
        $this->fail('Student row not found in CSV export');
    }

    // ── CSV null handling ───────────────────────────────────

    /**
     * Verify that null fields (removed_at for active enrolment, nullable
     * capacity) produce empty CSV cells, not the string "null" or other
     * placeholder.
     */
    public function test_csv_null_fields_are_empty_not_literal_null(): void
    {
        User::create([
            'name' => 'Filler',
            'email' => 'filler@test.edu',
            'password' => bcrypt('p'),
            'role' => 'school_teacher',
            'school_id' => $this->school->id,
        ]);
        $student = User::create([
            'name' => 'Active Student',
            'email' => 'active@test.edu',
            'password' => bcrypt('p'),
            'role' => 'student',
            'school_id' => $this->school->id,
        ]);

        CohortEnrolment::create([
            'cohort_id' => $this->cohort->id,
            'student_id' => $student->id,
            'status' => 'enrolled',
            'enrolled_at' => now(),
            // removed_at is null — student is still active
        ]);

        $response = $this->get('/api/school/enrolments/export', $this->adminHeaders());
        $content = $response->streamedContent();
        $lines = explode("\n", trim($content));
        $headers = str_getcsv($lines[0]);

        for ($i = 1; $i < count($lines); $i++) {
            $values = str_getcsv($lines[$i]);
            if (count($values) === count($headers)) {
                $row = array_combine($headers, $values);
                if ($row['student_email'] === 'active@test.edu') {
                    // removed_at should be empty string, not "null" or "NULL"
                    $this->assertSame('', $row['removed_at'], 'Null removed_at should be empty in CSV');
                    $this->assertNotSame('null', strtolower($row['removed_at']), 'Null field must not be literal "null"');
                    return;
                }
            }
        }
        $this->fail('Student row not found in CSV');
    }

    // ── JSON response encoding ──────────────────────────────

    /**
     * Verify that JSON responses use proper encoding for special characters.
     * The Content-Type should be application/json and angle brackets, quotes,
     * and ampersands must survive the round-trip.
     */
    public function test_json_response_preserves_special_characters(): void
    {
        $name = '<script>alert("xss")</script> & "quotes" — résumé 🎓';

        $response = $this->postJson('/api/school/cohorts', [
            'experience_id' => $this->experience->id,
            'name' => $name,
            'start_date' => '2026-04-01',
            'end_date' => '2026-08-01',
        ], $this->teacherHeaders());

        $response->assertStatus(201);

        // Verify the response Content-Type is JSON
        $this->assertStringContainsString('application/json', $response->headers->get('Content-Type'));

        // Verify the JSON-decoded value matches exactly
        $this->assertSame($name, $response->json('name'));

        // Verify the raw JSON contains properly encoded characters
        $rawJson = $response->getContent();
        // JSON encodes < > as unicode escapes or literal — either is valid
        // The key test: when decoded, we get the exact original string
        $decoded = json_decode($rawJson, true);
        $this->assertSame($name, $decoded['name']);
    }

    // ── Cohort update preserves data ────────────────────────

    /**
     * Verify that updating a cohort name with special characters stores
     * and returns the exact value. Tests the PUT/update path separately
     * from the POST/create path.
     */
    public function test_update_cohort_preserves_special_characters(): void
    {
        $original = Cohort::create([
            'experience_id' => $this->experience->id,
            'school_id' => $this->school->id,
            'name' => 'Original Name',
            'status' => 'not_started',
            'start_date' => '2026-04-01',
            'end_date' => '2026-08-01',
        ]);

        $newName = "Updated <em>Name</em> — O'Brien's \"Best\" & Co. 🎓 日本語";

        $response = $this->putJson("/api/school/cohorts/{$original->id}", [
            'name' => $newName,
        ], $this->teacherHeaders());

        $response->assertStatus(200);
        $this->assertSame($newName, $response->json('name'));

        // Verify DB
        $dbValue = Cohort::withoutGlobalScopes()->find($original->id)->getRawOriginal('name');
        $this->assertSame($newName, $dbValue);
    }

    // ── CSV column count consistency ────────────────────────

    /**
     * Verify that every row in the CSV has exactly the same number of
     * columns as the header, even when values contain commas, quotes,
     * or newlines that could break naive CSV parsers.
     */
    public function test_csv_rows_have_consistent_column_count(): void
    {
        $troublemakers = [
            ['name' => 'Comma, Student',     'email' => 'comma@test.edu'],
            ['name' => 'Quote "Student"',    'email' => 'quote@test.edu'],
            ['name' => "Newline\nStudent",   'email' => 'newline@test.edu'],
            ['name' => "Tab\tStudent",       'email' => 'tab@test.edu'],
        ];

        User::create([
            'name' => 'Filler',
            'email' => 'filler2@test.edu',
            'password' => bcrypt('p'),
            'role' => 'school_teacher',
            'school_id' => $this->school->id,
        ]);

        foreach ($troublemakers as $case) {
            $student = User::create([
                'name' => $case['name'],
                'email' => $case['email'],
                'password' => bcrypt('p'),
                'role' => 'student',
                'school_id' => $this->school->id,
            ]);
            CohortEnrolment::create([
                'cohort_id' => $this->cohort->id,
                'student_id' => $student->id,
                'status' => 'enrolled',
                'enrolled_at' => now(),
            ]);
        }

        $response = $this->get('/api/school/enrolments/export', $this->adminHeaders());
        $content = $response->streamedContent();

        // Use PHP's stream CSV parser which handles quoted fields correctly
        $handle = fopen('php://memory', 'r+');
        fwrite($handle, $content);
        rewind($handle);

        $headers = fgetcsv($handle);
        $headerCount = count($headers);
        $rowNum = 1;

        while (($row = fgetcsv($handle)) !== false) {
            $rowNum++;
            $this->assertCount(
                $headerCount,
                $row,
                "Row {$rowNum} has " . count($row) . " columns, expected {$headerCount}"
            );
        }
        fclose($handle);

        // Verify we actually had data rows (not just a header)
        $this->assertGreaterThan(1, $rowNum, 'CSV should have at least one data row');
    }
}
