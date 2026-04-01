<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Experience;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * End-to-end data integrity tests for the Experience service.
 *
 * Verifies that data passes through the full request lifecycle — from JSON
 * input, through validation and service layers, into the database, back out
 * via JSON responses, and into CSV exports — without any corruption,
 * mutation, truncation, or encoding loss.
 */
class DataIntegrityTest extends TestCase
{
    use DatabaseMigrations;

    private School $school;
    private User $admin;
    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::create([
            'name' => 'Ridgewood Academy',
            'code' => 'RIDGE',
            'is_active' => true,
        ]);

        // ID 1 → test-admin-token
        $this->admin = User::create([
            'name' => 'Admin',
            'email' => 'admin@ridgewood.edu',
            'password' => bcrypt('password'),
            'role' => 'school_admin',
            'school_id' => $this->school->id,
        ]);

        // ID 2 → test-teacher-token
        $this->teacher = User::create([
            'name' => 'Teacher',
            'email' => 'teacher@ridgewood.edu',
            'password' => bcrypt('password'),
            'role' => 'school_teacher',
            'school_id' => $this->school->id,
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

    /**
     * Strings that historically cause problems with sanitizers, encoders,
     * or formatters.
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
            'backslash'         => 'Path: C:\\Users\\test',
            'formula_equals'    => '=SUM(A1:A10)',
            'formula_plus'      => '+1-555-0123',
            'formula_minus'     => '-Advanced Level',
            'formula_at'        => '@special_experience',
            'percent'           => '100% Complete',
            'parentheses'       => 'Group (A) - Section [B]',
            'pipe'              => 'Option A | Option B',
            'hash'              => 'Issue #42',
            'dollar'            => 'Cost: $50.00',
        ];
    }

    // ── Experience name: API → DB → JSON round-trip ─────────

    /**
     * Create an experience with each tricky string as its name, then verify
     * the exact string is stored in the DB and returned in the JSON response.
     */
    public function test_experience_names_survive_api_to_db_round_trip(): void
    {
        Http::fake(['*' => Http::response(['data' => []])]);

        foreach ($this->trickyStrings() as $label => $input) {
            $response = $this->postJson('/api/school/experiences', [
                'name' => $input,
                'description' => "Description for {$label}",
                'course_ids' => [1],
            ], $this->adminHeaders());

            $response->assertStatus(201, "Failed to create experience for case: {$label}");

            $id = $response->json('id');

            // Verify JSON response contains exact input
            $this->assertSame(
                $input,
                $response->json('name'),
                "JSON response mismatch for case: {$label}"
            );

            // Verify database contains exact input
            $dbValue = Experience::withoutGlobalScopes()->find($id)->getRawOriginal('name');
            $this->assertSame(
                $input,
                $dbValue,
                "Database value mismatch for case: {$label}"
            );

            // Verify GET endpoint returns exact input
            $getResponse = $this->getJson("/api/school/experiences/{$id}", $this->adminHeaders());
            $getResponse->assertStatus(200);
            $this->assertSame(
                $input,
                $getResponse->json('name'),
                "GET response mismatch for case: {$label}"
            );
        }
    }

    // ── Experience description: API → DB → JSON round-trip ──

    /**
     * Same as name test but for the description field, which has a higher
     * max length (1000 chars) and is more likely to contain prose with
     * special characters.
     */
    public function test_experience_descriptions_survive_api_to_db_round_trip(): void
    {
        Http::fake(['*' => Http::response(['data' => []])]);

        foreach ($this->trickyStrings() as $label => $input) {
            $response = $this->postJson('/api/school/experiences', [
                'name' => "Exp for {$label}",
                'description' => $input,
                'course_ids' => [1],
            ], $this->adminHeaders());

            $response->assertStatus(201, "Failed to create for case: {$label}");

            $id = $response->json('id');

            // Verify database contains exact input
            $dbValue = Experience::withoutGlobalScopes()->find($id)->getRawOriginal('description');
            $this->assertSame(
                $input,
                $dbValue,
                "Database description mismatch for case: {$label}"
            );
        }
    }

    // ── Experience update preserves data ────────────────────

    /**
     * Verify that updating an experience name and description preserves
     * special characters through the PUT/update path.
     */
    public function test_update_experience_preserves_special_characters(): void
    {
        Http::fake(['*' => Http::response(['data' => []])]);

        $experience = Experience::create([
            'school_id' => $this->school->id,
            'name' => 'Original',
            'description' => 'Original description',
            'status' => 'active',
            'created_by' => $this->teacher->id,
        ]);

        $newName = "Updated <em>Name</em> — O'Brien's \"Best\" & Co. 🎓";
        $newDesc = "Math: 2<3 and 5>2\nLine 2 with &amp; entities\n日本語";

        $response = $this->putJson("/api/school/experiences/{$experience->id}", [
            'name' => $newName,
            'description' => $newDesc,
        ], $this->adminHeaders());

        $response->assertStatus(200);
        $this->assertSame($newName, $response->json('name'));

        // Verify DB
        $fresh = Experience::withoutGlobalScopes()->find($experience->id);
        $this->assertSame($newName, $fresh->getRawOriginal('name'));
        $this->assertSame($newDesc, $fresh->getRawOriginal('description'));
    }

    // ── CSV export: upstream data → CSV round-trip ──────────

    /**
     * Mock upstream enrolment data with tricky strings, export CSV, parse it,
     * and verify every value matches what was sent by the upstream service.
     */
    public function test_csv_export_preserves_upstream_data_verbatim(): void
    {
        $testStudents = [
            ['id' => 10, 'name' => "O'Brien, James",       'email' => 'obrien@school.edu',  'cohort' => 'Cohort A'],
            ['id' => 11, 'name' => 'Ñoño García',          'email' => 'nono@school.edu',    'cohort' => 'Café Cohort'],
            ['id' => 12, 'name' => '=SUM(A1:A10)',         'email' => '+1test@school.edu',  'cohort' => 'Formula Cohort'],
            ['id' => 13, 'name' => '"Quoted" Student',     'email' => 'quoted@school.edu',  'cohort' => 'Quote "Cohort"'],
            ['id' => 14, 'name' => '<b>Bold</b> Student',  'email' => 'bold@school.edu',    'cohort' => '<em>Cohort</em>'],
            ['id' => 15, 'name' => 'Smith & Wesson',       'email' => 'smith@school.edu',   'cohort' => 'Art & Design'],
            ['id' => 16, 'name' => '-Negative Name',       'email' => '-neg@school.edu',    'cohort' => '-Cohort'],
            ['id' => 17, 'name' => '@At Student',          'email' => '@at@school.edu',     'cohort' => '@Cohort'],
            ['id' => 18, 'name' => '日本語 Student',       'email' => 'jp@school.edu',      'cohort' => '日本語 Cohort'],
            ['id' => 19, 'name' => 'Emoji 🎓',            'email' => 'emoji@school.edu',   'cohort' => 'Grad 🎓'],
        ];

        $mockData = array_map(fn($s) => [
            'student_id' => $s['id'],
            'name' => $s['name'],
            'email' => $s['email'],
            'cohort_assignments' => [
                [
                    'cohort_id' => 1,
                    'cohort_name' => $s['cohort'],
                    'status' => 'enrolled',
                    'enrolled_at' => '2026-03-01T00:00:00+00:00',
                ],
            ],
            'assignment_status' => 'assigned',
        ], $testStudents);

        Http::fake([
            '*/api/school/enrolments*' => Http::response(['data' => $mockData]),
        ]);

        $experience = Experience::create([
            'school_id' => $this->school->id,
            'name' => 'Test Experience',
            'description' => 'For CSV testing',
            'status' => 'active',
            'created_by' => $this->teacher->id,
        ]);

        $response = $this->get(
            "/api/school/experiences/{$experience->id}/students/export",
            $this->teacherHeaders()
        );
        $response->assertStatus(200);

        // Parse CSV using PHP's stream parser (handles quoted fields correctly)
        $content = $response->streamedContent();
        $handle = fopen('php://memory', 'r+');
        fwrite($handle, $content);
        rewind($handle);

        $headers = fgetcsv($handle);
        $csvRows = [];
        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) === count($headers)) {
                $csvRows[] = array_combine($headers, $row);
            }
        }
        fclose($handle);

        // Verify each student's data survived the CSV round-trip
        foreach ($testStudents as $expected) {
            $found = false;
            foreach ($csvRows as $csvRow) {
                if ($csvRow['student_email'] === $expected['email']) {
                    $found = true;
                    $this->assertSame(
                        $expected['name'],
                        $csvRow['student_name'],
                        "CSV name mismatch for: {$expected['email']}"
                    );
                    $this->assertSame(
                        $expected['cohort'],
                        $csvRow['cohort_name'],
                        "CSV cohort mismatch for: {$expected['email']}"
                    );
                    $this->assertSame(
                        'enrolled',
                        $csvRow['status'],
                        "CSV status mismatch for: {$expected['email']}"
                    );
                    break;
                }
            }
            $this->assertTrue($found, "Student not found in CSV: {$expected['email']}");
        }
    }

    // ── CSV column count consistency ────────────────────────

    /**
     * Verify every CSV row has the same number of columns as the header,
     * even when values contain commas, quotes, or newlines.
     */
    public function test_csv_rows_have_consistent_column_count(): void
    {
        Http::fake([
            '*/api/school/enrolments*' => Http::response([
                'data' => [
                    [
                        'student_id' => 10,
                        'name' => 'Comma, Student',
                        'email' => 'comma@test.edu',
                        'cohort_assignments' => [
                            ['cohort_id' => 1, 'cohort_name' => 'Quote "Cohort"', 'status' => 'enrolled', 'enrolled_at' => '2026-03-01'],
                        ],
                        'assignment_status' => 'assigned',
                    ],
                    [
                        'student_id' => 11,
                        'name' => "Newline\nStudent",
                        'email' => 'newline@test.edu',
                        'cohort_assignments' => [
                            ['cohort_id' => 2, 'cohort_name' => "Tab\tCohort", 'status' => 'enrolled', 'enrolled_at' => '2026-03-01'],
                        ],
                        'assignment_status' => 'assigned',
                    ],
                ],
            ]),
        ]);

        $experience = Experience::create([
            'school_id' => $this->school->id,
            'name' => 'CSV Column Test',
            'description' => 'Test',
            'status' => 'active',
            'created_by' => $this->teacher->id,
        ]);

        $response = $this->get(
            "/api/school/experiences/{$experience->id}/students/export",
            $this->teacherHeaders()
        );
        $response->assertStatus(200);

        $content = $response->streamedContent();
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

        $this->assertGreaterThan(1, $rowNum, 'CSV should have at least one data row');
    }

    // ── JSON response encoding ──────────────────────────────

    /**
     * Verify JSON responses correctly encode and return special characters.
     */
    public function test_json_response_preserves_special_characters(): void
    {
        Http::fake(['*' => Http::response(['data' => []])]);

        $name = '<script>alert("xss")</script> & "quotes" — résumé 🎓';
        $desc = "Math: 2<3\nUnicode: 日本語\tTab & entities: &amp;";

        $response = $this->postJson('/api/school/experiences', [
            'name' => $name,
            'description' => $desc,
            'course_ids' => [1],
        ], $this->adminHeaders());

        $response->assertStatus(201);
        $this->assertStringContainsString('application/json', $response->headers->get('Content-Type'));

        // Verify JSON-decoded values match exactly
        $this->assertSame($name, $response->json('name'));

        // Verify raw JSON decodes correctly
        $decoded = json_decode($response->getContent(), true);
        $this->assertSame($name, $decoded['name']);

        // Verify DB stores it exactly
        $dbExp = Experience::withoutGlobalScopes()->find($response->json('id'));
        $this->assertSame($desc, $dbExp->getRawOriginal('description'));
    }

    // ── Missing upstream status defaults to 'unknown' ───────

    /**
     * Verify that when the upstream enrolment service omits the status field,
     * the CSV export shows 'unknown' rather than silently defaulting to
     * 'enrolled' (which would mask removed students).
     */
    public function test_missing_status_defaults_to_unknown_not_enrolled(): void
    {
        Http::fake([
            '*/api/school/enrolments*' => Http::response([
                'data' => [
                    [
                        'student_id' => 10,
                        'name' => 'Missing Status Student',
                        'email' => 'missing@test.edu',
                        'cohort_assignments' => [
                            [
                                'cohort_id' => 1,
                                'cohort_name' => 'Cohort A',
                                // 'status' deliberately omitted
                                'enrolled_at' => '2026-03-01',
                            ],
                        ],
                        'assignment_status' => 'assigned',
                    ],
                ],
            ]),
        ]);

        $experience = Experience::create([
            'school_id' => $this->school->id,
            'name' => 'Status Default Test',
            'description' => 'Test',
            'status' => 'active',
            'created_by' => $this->teacher->id,
        ]);

        // Test via CSV export
        $response = $this->get(
            "/api/school/experiences/{$experience->id}/students/export",
            $this->teacherHeaders()
        );
        $response->assertStatus(200);

        $content = $response->streamedContent();
        $handle = fopen('php://memory', 'r+');
        fwrite($handle, $content);
        rewind($handle);

        $headers = fgetcsv($handle);
        $row = fgetcsv($handle);
        $data = array_combine($headers, $row);
        fclose($handle);

        $this->assertSame('unknown', $data['status'], 'Missing status must default to "unknown", not "enrolled"');

        // Also test via JSON student list
        $jsonResponse = $this->getJson(
            "/api/school/experiences/{$experience->id}/students",
            $this->teacherHeaders()
        );
        $jsonResponse->assertStatus(200);
        $students = $jsonResponse->json('data');
        $this->assertSame('unknown', $students[0]['status']);
    }
}
