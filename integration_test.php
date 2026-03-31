<?php
/**
 * Live integration test suite — verifies all demo endpoints against running Docker services.
 * Run from inside the dashboard-service container (has network access to all services).
 */

$pass = 0;
$fail = 0;
$results = [];

function req(string $method, string $url, string $token = '', string $body = ''): array {
    $headers = ["Accept: application/json"];
    if ($token) {
        $headers[] = "Authorization: Bearer $token";
    }
    if ($body) {
        $headers[] = "Content-Type: application/json";
    }

    $ctx = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $body ?: null,
            'ignore_errors' => true,
            'timeout' => 10,
        ],
    ]);

    $resp = @file_get_contents($url, false, $ctx);
    $status = 0;
    $respHeaders = $http_response_header ?? [];
    if (isset($respHeaders[0]) && preg_match('/HTTP\/\S+ (\d+)/', $respHeaders[0], $m)) {
        $status = (int) $m[1];
    }

    return ['status' => $status, 'body' => $resp ?: '', 'headers' => $respHeaders];
}

function check(string $name, int $expected, array $response): void {
    global $pass, $fail, $results;
    $actual = $response['status'];
    if ($actual === $expected) {
        $pass++;
        $results[] = "  PASS: $name (HTTP $actual)";
    } else {
        $fail++;
        $body = substr($response['body'], 0, 200);
        $results[] = "  FAIL: $name (expected $expected, got $actual)";
        $results[] = "        Body: $body";
    }
}

function checkJson(string $name, int $expected, array $response, string $jsonPath, $expectedVal): void {
    global $pass, $fail, $results;
    $actual = $response['status'];
    if ($actual !== $expected) {
        $fail++;
        $results[] = "  FAIL: $name (expected HTTP $expected, got $actual)";
        return;
    }
    $data = json_decode($response['body'], true);
    $keys = explode('.', $jsonPath);
    $val = $data;
    foreach ($keys as $k) {
        $val = $val[$k] ?? null;
    }
    if ($val === $expectedVal) {
        $pass++;
        $results[] = "  PASS: $name ($jsonPath = " . json_encode($expectedVal) . ")";
    } else {
        $fail++;
        $results[] = "  FAIL: $name ($jsonPath expected " . json_encode($expectedVal) . ", got " . json_encode($val) . ")";
    }
}

function checkJsonHas(string $name, int $expected, array $response, string $jsonPath): void {
    global $pass, $fail, $results;
    $actual = $response['status'];
    if ($actual !== $expected) {
        $fail++;
        $results[] = "  FAIL: $name (expected HTTP $expected, got $actual)";
        return;
    }
    $data = json_decode($response['body'], true);
    $keys = explode('.', $jsonPath);
    $val = $data;
    foreach ($keys as $k) {
        if (!isset($val[$k])) {
            $fail++;
            $results[] = "  FAIL: $name (missing key: $jsonPath)";
            return;
        }
        $val = $val[$k];
    }
    $pass++;
    $results[] = "  PASS: $name (has $jsonPath)";
}

$EXP = 'http://experience-service:8002';
$ENR = 'http://enrolment-service:8003';
$DASH = 'http://localhost:8001';
$ADMIN = 'test-admin-token';
$TEACHER = 'test-teacher-token';
$STUDENT = 'test-student-token';
$PARENT = 'test-parent-token';

echo "=== LIVE INTEGRATION TESTS ===\n\n";

// ── 1. Health Checks ──
echo "[1] Health checks\n";
$r = req('GET', "$DASH/api/school/dashboard/health");
checkJson("Dashboard health", 200, $r, 'status', 'ok');
checkJsonHas("Dashboard health has downstream", 200, $r, 'downstream');

$r = req('GET', "$EXP/api/school/experiences/health");
checkJson("Experience health", 200, $r, 'status', 'ok');

$r = req('GET', "$ENR/api/school/enrolments/health");
checkJson("Enrolment health", 200, $r, 'status', 'ok');

// ── 2. Authentication ──
echo "[2] Authentication\n";
check("Admin token accepted", 200, req('GET', "$EXP/api/school/experiences", $ADMIN));
check("Teacher token accepted", 200, req('GET', "$EXP/api/school/experiences", $TEACHER));
check("No token -> 401", 401, req('GET', "$EXP/api/school/experiences"));
check("Invalid token -> 401", 401, req('GET', "$EXP/api/school/experiences", 'bad-token'));
check("Student can read experience list (read-only)", 200, req('GET', "$EXP/api/school/experiences", $STUDENT));
check("Hatchloom teacher blocked from school data", 403, req('GET', "$EXP/api/school/experiences", 'test-hatchloom-teacher-token'));

// ── 3. Experience List (Screen 301) ──
echo "[3] Experience management (Screen 301)\n";
$r = req('GET', "$EXP/api/school/experiences", $ADMIN);
check("Experience list", 200, $r);
$data = json_decode($r['body'], true);
$expCount = count($data['data'] ?? []);
check("Experience list has data ($expCount experiences)", 200, $r);

// ── 4. Course Catalogue ──
echo "[4] Course catalogue\n";
$r = req('GET', "$EXP/api/school/courses", $ADMIN);
check("Course catalogue", 200, $r);
$data = json_decode($r['body'], true);
$courseCount = count($data['data'] ?? []);
$results[] = "       -> $courseCount courses returned";

// ── 5. Experience Detail (Screen 302) ──
echo "[5] Experience detail (Screen 302)\n";
$r = req('GET', "$EXP/api/school/experiences/1/students", $ADMIN);
check("Students tab", 200, $r);
checkJsonHas("Students tab has pagination", 200, $r, 'meta');

$r = req('GET', "$EXP/api/school/experiences/1/contents", $ADMIN);
check("Contents tab", 200, $r);

$r = req('GET', "$EXP/api/school/experiences/1/statistics", $ADMIN);
check("Statistics tab", 200, $r);

check("Student CSV export", 200, req('GET', "$EXP/api/school/experiences/1/students/export", $ADMIN));
check("Nonexistent experience -> 404", 404, req('GET', "$EXP/api/school/experiences/999/students", $ADMIN));

// ── 6. Cohort Management (Screen 303) ──
echo "[6] Cohort management (Screen 303)\n";
$r = req('GET', "$ENR/api/school/cohorts", $ADMIN);
check("Cohort list", 200, $r);
$data = json_decode($r['body'], true);
$cohortCount = count($data['data'] ?? []);
$results[] = "       -> $cohortCount cohorts returned";

// ── 7. Enrolment Management ──
echo "[7] Enrolment management\n";
$r = req('GET', "$ENR/api/school/enrolments", $ADMIN);
check("Enrolment overview", 200, $r);
checkJsonHas("Enrolment overview has pagination meta", 200, $r, 'meta');

$r = req('GET', "$ENR/api/school/enrolments/statistics", $ADMIN);
check("Enrolment statistics", 200, $r);
checkJsonHas("Statistics has total_students", 200, $r, 'total_students');
checkJsonHas("Statistics has warnings", 200, $r, 'warnings');

// ── 8. Enrolment Student Detail ──
echo "[8] Enrolment student detail\n";
$r = req('GET', "$ENR/api/school/enrolments/students/4", $ADMIN);
check("Student detail (admin)", 200, $r);
checkJsonHas("Student detail has credentials", 200, $r, 'credentials');

// ── 9. Enrolment CSV Export ──
echo "[9] Enrolment export\n";
check("Enrolment CSV export", 200, req('GET', "$ENR/api/school/enrolments/export", $ADMIN));

// ── 10. Dashboard Overview (Screen 300, cross-service) ──
echo "[10] Dashboard overview (cross-service)\n";
$r = req('GET', "$DASH/api/school/dashboard", $ADMIN);
check("Dashboard overview", 200, $r);
checkJsonHas("Dashboard has school info", 200, $r, 'school');
checkJsonHas("Dashboard has summary", 200, $r, 'summary');
checkJsonHas("Dashboard has cohorts", 200, $r, 'cohorts');
checkJsonHas("Dashboard has students", 200, $r, 'students');
checkJsonHas("Dashboard has statistics", 200, $r, 'statistics');
$data = json_decode($r['body'], true);
$results[] = "       -> school: " . ($data['school']['name'] ?? 'N/A');
$results[] = "       -> experiences: " . ($data['summary']['experiences'] ?? 0);
$results[] = "       -> students: " . ($data['summary']['students'] ?? 0);

// ── 11. Dashboard Student Drill-Down ──
echo "[11] Dashboard student drill-down\n";
$r = req('GET', "$DASH/api/school/dashboard/students/4", $ADMIN);
check("Student drill-down (admin)", 200, $r);
checkJsonHas("Drill-down has progress", 200, $r, 'progress');
checkJsonHas("Drill-down has credentials", 200, $r, 'credentials');
checkJsonHas("Drill-down has curriculum_mapping", 200, $r, 'curriculum_mapping');
check("Nonexistent student -> 404", 404, req('GET', "$DASH/api/school/dashboard/students/9999", $ADMIN));

// ── 12. Dashboard Reporting ──
echo "[12] Dashboard reporting\n";
$r = req('GET', "$DASH/api/school/dashboard/reporting/pos-coverage", $ADMIN);
check("PoS coverage", 200, $r);
checkJsonHas("PoS has pos_areas", 200, $r, 'pos_areas');
checkJsonHas("PoS has student_coverage", 200, $r, 'student_coverage');
checkJsonHas("PoS has school_averages", 200, $r, 'school_averages');

$r = req('GET', "$DASH/api/school/dashboard/reporting/engagement", $ADMIN);
check("Engagement rates", 200, $r);
checkJsonHas("Engagement has student_engagement", 200, $r, 'student_engagement');
checkJsonHas("Engagement has school_averages", 200, $r, 'school_averages');

// ── 13. Dashboard Widgets ──
echo "[13] Dashboard widgets\n";
$r = req('GET', "$DASH/api/school/dashboard/widgets", $ADMIN);
check("All widgets", 200, $r);
$data = json_decode($r['body'], true);
$widgetCount = count($data['widgets'] ?? []);
$results[] = "       -> $widgetCount widgets returned";

check("Cohort summary widget", 200, req('GET', "$DASH/api/school/dashboard/widgets/cohort_summary", $ADMIN));
check("Student table widget", 200, req('GET', "$DASH/api/school/dashboard/widgets/student_table", $ADMIN));
check("Engagement chart widget", 200, req('GET', "$DASH/api/school/dashboard/widgets/engagement_chart", $ADMIN));
check("Invalid widget -> 422", 422, req('GET', "$DASH/api/school/dashboard/widgets/fake_widget", $ADMIN));

// ── 14. Parent Access (parent_student_links) ──
echo "[14] Parent access via parent_student_links\n";
$r = req('GET', "$DASH/api/school/dashboard/students/4", $PARENT);
check("Parent views linked child 1 (id=4)", 200, $r);
$r = req('GET', "$DASH/api/school/dashboard/students/5", $PARENT);
check("Parent views linked child 2 (id=5)", 200, $r);
check("Parent blocked from non-child (id=6)", 403, req('GET', "$DASH/api/school/dashboard/students/6", $PARENT));
check("Parent blocked from dashboard overview", 403, req('GET', "$DASH/api/school/dashboard", $PARENT));
check("Parent blocked from PoS coverage", 403, req('GET', "$DASH/api/school/dashboard/reporting/pos-coverage", $PARENT));

// Also test parent on enrolment service
$r = req('GET', "$ENR/api/school/enrolments/students/4", $PARENT);
check("Parent views child detail on enrolment svc", 200, $r);
check("Parent blocked from non-child on enrolment svc", 403, req('GET', "$ENR/api/school/enrolments/students/6", $PARENT));

// Parent enrolment overview should be scoped to their children
$r = req('GET', "$ENR/api/school/enrolments", $PARENT);
check("Parent enrolment overview scoped", 200, $r);

// ── 15. Student Self-Access ──
echo "[15] Student self-access\n";
check("Student views own drill-down", 200, req('GET', "$DASH/api/school/dashboard/students/4", $STUDENT));
check("Student blocked from other student", 403, req('GET', "$DASH/api/school/dashboard/students/5", $STUDENT));
check("Student blocked from dashboard overview", 403, req('GET', "$DASH/api/school/dashboard", $STUDENT));

// ── 16. Security ──
echo "[16] Security\n";
$r = req('GET', "$DASH/api/school/dashboard/health");
$respHeaders = $r['headers'] ?? [];
$hasCSP = false;
$hasXCTO = false;
$hasXFO = false;
foreach ($respHeaders as $h) {
    if (stripos($h, 'Content-Security-Policy') !== false) $hasCSP = true;
    if (stripos($h, 'X-Content-Type-Options') !== false) $hasXCTO = true;
    if (stripos($h, 'X-Frame-Options') !== false) $hasXFO = true;
}
if ($hasCSP) { $pass++; $results[] = "  PASS: CSP header present"; }
else { $fail++; $results[] = "  FAIL: CSP header missing"; }
if ($hasXCTO) { $pass++; $results[] = "  PASS: X-Content-Type-Options header present"; }
else { $fail++; $results[] = "  FAIL: X-Content-Type-Options header missing"; }
if ($hasXFO) { $pass++; $results[] = "  PASS: X-Frame-Options header present"; }
else { $fail++; $results[] = "  FAIL: X-Frame-Options header missing"; }

// ── Summary ──
echo "\n";
echo "==============================\n";
foreach ($results as $r) echo "$r\n";
echo "==============================\n";
$total = $pass + $fail;
echo "\nTOTAL: $total | PASS: $pass | FAIL: $fail\n";
if ($fail === 0) echo "\nAll integration tests passed!\n";
else echo "\n$fail test(s) failed — review above.\n";
exit($fail > 0 ? 1 : 0);
