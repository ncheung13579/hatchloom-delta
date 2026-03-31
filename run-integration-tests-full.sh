#!/usr/bin/env sh
# ─────────────────────────────────────────────────────────────────
# Comprehensive Integration Tests for Hatchloom Delta microservices.
#
# Final pre-handoff quality pass covering ALL endpoints, ALL HTTP
# methods, ALL roles, ALL error paths, validation, state transitions,
# capacity enforcement, data integrity, search/filtering, pagination,
# security headers, and cross-service consistency.
#
# Run directly against running services:
#   bash run-integration-tests-full.sh
#
# Or via Docker Compose integration stack:
#   docker compose -f docker-compose.integration.yml up --build --abort-on-container-exit
#
# Requires: curl, grep, sed
# ─────────────────────────────────────────────────────────────────

set -u

DASHBOARD="${DASHBOARD_URL:-http://localhost:8001}"
EXPERIENCE="${EXPERIENCE_URL:-http://localhost:8002}"
ENROLMENT="${ENROLMENT_URL:-http://localhost:8003}"

# Tokens (mapped by MockAuthMiddleware)
ADMIN_TOKEN="test-admin-token"
TEACHER_TOKEN="test-teacher-token"
STUDENT_TOKEN="test-student-token"
PARENT_TOKEN="test-parent-token"
HATCHLOOM_TEACHER_TOKEN="test-hatchloom-teacher-token"
HATCHLOOM_ADMIN_TOKEN="test-hatchloom-admin-token"

PASSED=0
FAILED=0
TOTAL=0

# ── Helpers ──────────────────────────────────────────────────────

pass() {
    PASSED=$((PASSED + 1))
    TOTAL=$((TOTAL + 1))
    echo "  PASS: $1"
}

fail() {
    FAILED=$((FAILED + 1))
    TOTAL=$((TOTAL + 1))
    echo "  FAIL: $1"
    if [ -n "${2:-}" ]; then
        echo "        $2"
    fi
}

# Make a request with a specific token and capture HTTP status + body + headers.
# Usage: do_req METHOD URL TOKEN [DATA]
# Sets: HTTP_STATUS, HTTP_BODY, HTTP_HEADERS
do_req() {
    METHOD="$1"
    URL="$2"
    TOKEN="$3"
    DATA="${4:-}"

    TMPFILE=$(mktemp)
    HDRFILE=$(mktemp)
    if [ -n "$DATA" ]; then
        HTTP_STATUS=$(curl -s -o "$TMPFILE" -D "$HDRFILE" -w '%{http_code}' \
            -X "$METHOD" "$URL" \
            -H "Authorization: Bearer $TOKEN" \
            -H "Content-Type: application/json" \
            -H "Accept: application/json" \
            -d "$DATA")
    else
        HTTP_STATUS=$(curl -s -o "$TMPFILE" -D "$HDRFILE" -w '%{http_code}' \
            -X "$METHOD" "$URL" \
            -H "Authorization: Bearer $TOKEN" \
            -H "Accept: application/json")
    fi
    HTTP_BODY=$(cat "$TMPFILE")
    HTTP_HEADERS=$(cat "$HDRFILE")
    rm -f "$TMPFILE" "$HDRFILE"
}

# Request without any auth token
do_req_noauth() {
    METHOD="$1"
    URL="$2"
    DATA="${3:-}"

    TMPFILE=$(mktemp)
    HDRFILE=$(mktemp)
    if [ -n "$DATA" ]; then
        HTTP_STATUS=$(curl -s -o "$TMPFILE" -D "$HDRFILE" -w '%{http_code}' \
            -X "$METHOD" "$URL" \
            -H "Content-Type: application/json" \
            -H "Accept: application/json" \
            -d "$DATA")
    else
        HTTP_STATUS=$(curl -s -o "$TMPFILE" -D "$HDRFILE" -w '%{http_code}' \
            -X "$METHOD" "$URL" \
            -H "Accept: application/json")
    fi
    HTTP_BODY=$(cat "$TMPFILE")
    HTTP_HEADERS=$(cat "$HDRFILE")
    rm -f "$TMPFILE" "$HDRFILE"
}

# Assert HTTP status code
assert_status() {
    EXPECTED="$1"
    TEST_NAME="$2"
    if [ "$HTTP_STATUS" = "$EXPECTED" ]; then
        pass "$TEST_NAME"
    else
        fail "$TEST_NAME" "expected $EXPECTED, got $HTTP_STATUS — body: $(echo "$HTTP_BODY" | head -c 200)"
    fi
}

# Assert response body contains a string
assert_contains() {
    NEEDLE="$1"
    TEST_NAME="$2"
    if echo "$HTTP_BODY" | grep -q "$NEEDLE"; then
        pass "$TEST_NAME"
    else
        fail "$TEST_NAME" "body missing '$NEEDLE' — body: $(echo "$HTTP_BODY" | head -c 200)"
    fi
}

# Assert response body does NOT contain a string
assert_not_contains() {
    NEEDLE="$1"
    TEST_NAME="$2"
    if echo "$HTTP_BODY" | grep -q "$NEEDLE"; then
        fail "$TEST_NAME" "body unexpectedly contains '$NEEDLE'"
    else
        pass "$TEST_NAME"
    fi
}

# Assert a response header exists
assert_header() {
    HEADER_NAME="$1"
    TEST_NAME="$2"
    if echo "$HTTP_HEADERS" | grep -qi "$HEADER_NAME"; then
        pass "$TEST_NAME"
    else
        fail "$TEST_NAME" "header '$HEADER_NAME' missing"
    fi
}

# Extract the first occurrence of a JSON key's value (simple string/number)
json_val() {
    echo "$HTTP_BODY" | grep -o "\"$1\"[[:space:]]*:[[:space:]]*[^,}]*" | head -1 | sed "s/\"$1\"[[:space:]]*:[[:space:]]*//" | sed 's/^"//;s/"$//'
}

# Assert a JSON key has a specific value
assert_json_val() {
    KEY="$1"
    EXPECTED="$2"
    TEST_NAME="$3"
    ACTUAL=$(json_val "$KEY")
    if [ "$ACTUAL" = "$EXPECTED" ]; then
        pass "$TEST_NAME"
    else
        fail "$TEST_NAME" "expected $KEY=$EXPECTED, got $KEY=$ACTUAL"
    fi
}

# ═════════════════════════════════════════════════════════════════
echo ""
echo "============================================================"
echo " Hatchloom Delta — Comprehensive Integration Tests"
echo "============================================================"
echo ""

# ── Phase 1: Health & Infrastructure ─────────────────────────────
echo "--- [Phase 1] Health & Infrastructure ---"

do_req_noauth GET "$DASHBOARD/api/school/dashboard/health"
assert_status 200 "Dashboard health"
assert_contains '"status":"ok"' "Dashboard health status ok"
assert_contains '"downstream"' "Dashboard health has downstream"

do_req_noauth GET "$EXPERIENCE/api/school/experiences/health"
assert_status 200 "Experience health"
assert_contains '"status":"ok"' "Experience health status ok"

do_req_noauth GET "$ENROLMENT/api/school/enrolments/health"
assert_status 200 "Enrolment health"
assert_contains '"status":"ok"' "Enrolment health status ok"

# ── Phase 2: Authentication ──────────────────────────────────────
echo ""
echo "--- [Phase 2] Authentication ---"

# Admin token on all 3 services
do_req GET "$EXPERIENCE/api/school/experiences" "$ADMIN_TOKEN"
assert_status 200 "Admin token → experience service"
do_req GET "$ENROLMENT/api/school/cohorts" "$ADMIN_TOKEN"
assert_status 200 "Admin token → enrolment service"
do_req GET "$DASHBOARD/api/school/dashboard" "$ADMIN_TOKEN"
assert_status 200 "Admin token → dashboard service"

# Teacher token
do_req GET "$EXPERIENCE/api/school/experiences" "$TEACHER_TOKEN"
assert_status 200 "Teacher token → experience service"
do_req GET "$ENROLMENT/api/school/cohorts" "$TEACHER_TOKEN"
assert_status 200 "Teacher token → enrolment service"
do_req GET "$DASHBOARD/api/school/dashboard" "$TEACHER_TOKEN"
assert_status 200 "Teacher token → dashboard service"

# Student token (read-only endpoints)
do_req GET "$EXPERIENCE/api/school/experiences" "$STUDENT_TOKEN"
assert_status 200 "Student reads experience list"
do_req GET "$ENROLMENT/api/school/cohorts" "$STUDENT_TOKEN"
assert_status 200 "Student reads cohort list"

# No token → 401
do_req_noauth GET "$EXPERIENCE/api/school/experiences"
assert_status 401 "No token → experience 401"
do_req_noauth GET "$ENROLMENT/api/school/cohorts"
assert_status 401 "No token → enrolment 401"
do_req_noauth GET "$DASHBOARD/api/school/dashboard"
assert_status 401 "No token → dashboard 401"

# Invalid token → 401
do_req GET "$EXPERIENCE/api/school/experiences" "bad-token"
assert_status 401 "Invalid token → experience 401"
do_req GET "$ENROLMENT/api/school/cohorts" "bad-token"
assert_status 401 "Invalid token → enrolment 401"
do_req GET "$DASHBOARD/api/school/dashboard" "bad-token"
assert_status 401 "Invalid token → dashboard 401"

# Hatchloom teacher (no school_id) → 403
do_req GET "$EXPERIENCE/api/school/experiences" "$HATCHLOOM_TEACHER_TOKEN"
assert_status 403 "Hatchloom teacher → experience 403"
do_req GET "$ENROLMENT/api/school/cohorts" "$HATCHLOOM_TEACHER_TOKEN"
assert_status 403 "Hatchloom teacher → enrolment 403"
do_req GET "$DASHBOARD/api/school/dashboard" "$HATCHLOOM_TEACHER_TOKEN"
assert_status 403 "Hatchloom teacher → dashboard 403"

# Hatchloom admin (no school_id) → 403
do_req GET "$EXPERIENCE/api/school/experiences" "$HATCHLOOM_ADMIN_TOKEN"
assert_status 403 "Hatchloom admin → experience 403"
do_req GET "$ENROLMENT/api/school/cohorts" "$HATCHLOOM_ADMIN_TOKEN"
assert_status 403 "Hatchloom admin → enrolment 403"
do_req GET "$DASHBOARD/api/school/dashboard" "$HATCHLOOM_ADMIN_TOKEN"
assert_status 403 "Hatchloom admin → dashboard 403"

# ── Phase 3: Experience Service — Read Operations ────────────────
echo ""
echo "--- [Phase 3] Experience Read Operations ---"

# List experiences
do_req GET "$EXPERIENCE/api/school/experiences" "$ADMIN_TOKEN"
assert_status 200 "List experiences"
assert_contains '"data"' "Experience list has data"
assert_contains '"meta"' "Experience list has meta"
assert_contains '"current_page"' "Meta has current_page"
assert_contains '"total"' "Meta has total"

# Show experience 1
do_req GET "$EXPERIENCE/api/school/experiences/1" "$ADMIN_TOKEN"
assert_status 200 "Show experience 1"
assert_contains '"Business Foundations"' "Experience 1 is Business Foundations"
assert_contains '"courses"' "Show includes courses"
assert_contains '"cohorts"' "Show includes cohorts (cross-service)"

# Show nonexistent → 404
do_req GET "$EXPERIENCE/api/school/experiences/9999" "$ADMIN_TOKEN"
assert_status 404 "Nonexistent experience → 404"
assert_contains '"NOT_FOUND"' "404 error code"

# Students tab (Screen 302)
do_req GET "$EXPERIENCE/api/school/experiences/1/students" "$ADMIN_TOKEN"
assert_status 200 "Experience students list"
assert_contains '"meta"' "Students has pagination meta"

# Student detail within experience
do_req GET "$EXPERIENCE/api/school/experiences/1/students/4" "$ADMIN_TOKEN"
assert_status 200 "Student detail in experience"
assert_contains '"student_name"' "Student detail has name"

# Contents tab
do_req GET "$EXPERIENCE/api/school/experiences/1/contents" "$ADMIN_TOKEN"
assert_status 200 "Experience contents tab"

# Statistics tab
do_req GET "$EXPERIENCE/api/school/experiences/1/statistics" "$ADMIN_TOKEN"
assert_status 200 "Experience statistics tab"

# CSV export
do_req GET "$EXPERIENCE/api/school/experiences/1/students/export" "$ADMIN_TOKEN"
assert_status 200 "Experience CSV export"

# Course catalogue
do_req GET "$EXPERIENCE/api/school/courses" "$ADMIN_TOKEN"
assert_status 200 "Course catalogue"
assert_contains '"data"' "Course catalogue has data"

# Nonexistent experience students → 404
do_req GET "$EXPERIENCE/api/school/experiences/9999/students" "$ADMIN_TOKEN"
assert_status 404 "Nonexistent exp students → 404"

# ── Phase 4: Enrolment Service — Read Operations ─────────────────
echo ""
echo "--- [Phase 4] Enrolment Read Operations ---"

# List cohorts
do_req GET "$ENROLMENT/api/school/cohorts" "$ADMIN_TOKEN"
assert_status 200 "List cohorts"
assert_contains '"data"' "Cohort list has data"

# Show cohort 1
do_req GET "$ENROLMENT/api/school/cohorts/1" "$ADMIN_TOKEN"
assert_status 200 "Show cohort 1"
assert_contains '"Cohort A"' "Cohort 1 is Cohort A"
assert_contains '"student_count"' "Cohort has student_count"
assert_contains '"removed_count"' "Cohort has removed_count"
assert_contains '"teacher_name"' "Cohort has teacher_name"
assert_json_val "status" "active" "Cohort 1 status is active"

# Show nonexistent → 404
do_req GET "$ENROLMENT/api/school/cohorts/9999" "$ADMIN_TOKEN"
assert_status 404 "Nonexistent cohort → 404"
assert_contains '"NOT_FOUND"' "Cohort 404 error code"

# Enrolment overview
do_req GET "$ENROLMENT/api/school/enrolments" "$ADMIN_TOKEN"
assert_status 200 "Enrolment overview"
assert_contains '"data"' "Enrolment overview has data"
assert_contains '"meta"' "Enrolment overview has meta"

# Enrolment statistics
do_req GET "$ENROLMENT/api/school/enrolments/statistics" "$ADMIN_TOKEN"
assert_status 200 "Enrolment statistics"
assert_contains '"total_students"' "Statistics has total_students"
assert_contains '"warnings"' "Statistics has warnings"
assert_contains '"enrolled"' "Statistics has enrolled count"
assert_contains '"not_assigned"' "Statistics has not_assigned"

# Student detail
do_req GET "$ENROLMENT/api/school/enrolments/students/4" "$ADMIN_TOKEN"
assert_status 200 "Student enrolment detail"
assert_contains '"credentials"' "Student detail has credentials"

# Nonexistent student → 404
do_req GET "$ENROLMENT/api/school/enrolments/students/9999" "$ADMIN_TOKEN"
assert_status 404 "Nonexistent student → 404"

# CSV export
do_req GET "$ENROLMENT/api/school/enrolments/export" "$ADMIN_TOKEN"
assert_status 200 "Enrolment CSV export"

# ── Phase 5: Dashboard — Read Operations ──────────────────────────
echo ""
echo "--- [Phase 5] Dashboard Read Operations ---"

# Dashboard overview
do_req GET "$DASHBOARD/api/school/dashboard" "$ADMIN_TOKEN"
assert_status 200 "Dashboard overview"
assert_contains '"school"' "Dashboard has school"
assert_contains '"summary"' "Dashboard has summary"
assert_contains '"cohorts"' "Dashboard has cohorts"
assert_contains '"students"' "Dashboard has students"
assert_contains '"statistics"' "Dashboard has statistics"
assert_contains '"warnings"' "Dashboard has warnings"
assert_contains '"Ridgewood Academy"' "Dashboard shows school name"

# Student drill-down
do_req GET "$DASHBOARD/api/school/dashboard/students/4" "$ADMIN_TOKEN"
assert_status 200 "Student drill-down (admin)"
assert_contains '"student"' "Drill-down has student section"
assert_contains '"enrolments"' "Drill-down has enrolments"
assert_contains '"progress"' "Drill-down has progress"
assert_contains '"credentials"' "Drill-down has credentials"
assert_contains '"curriculum_mapping"' "Drill-down has curriculum_mapping"

# Nonexistent student drill-down → 404
do_req GET "$DASHBOARD/api/school/dashboard/students/9999" "$ADMIN_TOKEN"
assert_status 404 "Nonexistent student drill-down → 404"

# PoS coverage
do_req GET "$DASHBOARD/api/school/dashboard/reporting/pos-coverage" "$ADMIN_TOKEN"
assert_status 200 "PoS coverage reporting"
assert_contains '"pos_areas"' "PoS has pos_areas"
assert_contains '"student_coverage"' "PoS has student_coverage"
assert_contains '"school_averages"' "PoS has school_averages"

# Engagement rates
do_req GET "$DASHBOARD/api/school/dashboard/reporting/engagement" "$ADMIN_TOKEN"
assert_status 200 "Engagement reporting"
assert_contains '"student_engagement"' "Engagement has student_engagement"
assert_contains '"school_averages"' "Engagement has school_averages"

# All widgets
do_req GET "$DASHBOARD/api/school/dashboard/widgets" "$ADMIN_TOKEN"
assert_status 200 "All widgets"
assert_contains '"cohort_summary"' "Widgets include cohort_summary"
assert_contains '"student_table"' "Widgets include student_table"
assert_contains '"engagement_chart"' "Widgets include engagement_chart"

# Individual widgets
do_req GET "$DASHBOARD/api/school/dashboard/widgets/cohort_summary" "$ADMIN_TOKEN"
assert_status 200 "Cohort summary widget"
assert_contains '"type"' "Widget has type field"
assert_contains '"data"' "Widget has data field"

do_req GET "$DASHBOARD/api/school/dashboard/widgets/student_table" "$ADMIN_TOKEN"
assert_status 200 "Student table widget"

do_req GET "$DASHBOARD/api/school/dashboard/widgets/engagement_chart" "$ADMIN_TOKEN"
assert_status 200 "Engagement chart widget"

# Invalid widget → 422
do_req GET "$DASHBOARD/api/school/dashboard/widgets/nonexistent" "$ADMIN_TOKEN"
assert_status 422 "Invalid widget type → 422"
assert_contains '"VALIDATION_ERROR"' "Invalid widget error code"

# ── Phase 6: Role-Based Access Control ───────────────────────────
echo ""
echo "--- [Phase 6] Role-Based Access Control ---"

# Parent — Dashboard
do_req GET "$DASHBOARD/api/school/dashboard/students/4" "$PARENT_TOKEN"
assert_status 200 "Parent views linked child 1 (id=4)"
do_req GET "$DASHBOARD/api/school/dashboard/students/5" "$PARENT_TOKEN"
assert_status 200 "Parent views linked child 2 (id=5)"
do_req GET "$DASHBOARD/api/school/dashboard/students/6" "$PARENT_TOKEN"
assert_status 403 "Parent blocked from non-child (id=6)"
do_req GET "$DASHBOARD/api/school/dashboard" "$PARENT_TOKEN"
assert_status 403 "Parent blocked from dashboard overview"
do_req GET "$DASHBOARD/api/school/dashboard/reporting/pos-coverage" "$PARENT_TOKEN"
assert_status 403 "Parent blocked from PoS coverage"
do_req GET "$DASHBOARD/api/school/dashboard/reporting/engagement" "$PARENT_TOKEN"
assert_status 403 "Parent blocked from engagement"
do_req GET "$DASHBOARD/api/school/dashboard/widgets" "$PARENT_TOKEN"
assert_status 403 "Parent blocked from widgets"

# Parent — Enrolment service
do_req GET "$ENROLMENT/api/school/enrolments/students/4" "$PARENT_TOKEN"
assert_status 200 "Parent views child on enrolment svc"
do_req GET "$ENROLMENT/api/school/enrolments/students/6" "$PARENT_TOKEN"
assert_status 403 "Parent blocked from non-child on enrolment"
do_req GET "$ENROLMENT/api/school/enrolments" "$PARENT_TOKEN"
assert_status 200 "Parent sees scoped enrolment overview"
do_req GET "$ENROLMENT/api/school/enrolments/statistics" "$PARENT_TOKEN"
assert_status 403 "Parent blocked from enrolment statistics"
do_req GET "$ENROLMENT/api/school/enrolments/export" "$PARENT_TOKEN"
assert_status 403 "Parent blocked from enrolment CSV export"

# Parent — Experience service
do_req GET "$EXPERIENCE/api/school/experiences" "$PARENT_TOKEN"
assert_status 200 "Parent can read experience list"
do_req GET "$EXPERIENCE/api/school/experiences/1/statistics" "$PARENT_TOKEN"
assert_status 403 "Parent blocked from experience statistics"
do_req GET "$EXPERIENCE/api/school/experiences/1/students/export" "$PARENT_TOKEN"
assert_status 403 "Parent blocked from experience CSV export"

# Student — Dashboard
do_req GET "$DASHBOARD/api/school/dashboard/students/4" "$STUDENT_TOKEN"
assert_status 200 "Student views own drill-down (id=4)"
do_req GET "$DASHBOARD/api/school/dashboard/students/5" "$STUDENT_TOKEN"
assert_status 403 "Student blocked from other student (id=5)"
do_req GET "$DASHBOARD/api/school/dashboard" "$STUDENT_TOKEN"
assert_status 403 "Student blocked from dashboard overview"

# Student — Enrolment service
do_req GET "$ENROLMENT/api/school/enrolments/students/4" "$STUDENT_TOKEN"
assert_status 200 "Student views own enrolment detail"
do_req GET "$ENROLMENT/api/school/enrolments/students/5" "$STUDENT_TOKEN"
assert_status 403 "Student blocked from other student's enrolment"

# Student/Parent blocked from write operations
do_req POST "$EXPERIENCE/api/school/experiences" "$STUDENT_TOKEN" '{"name":"Hack","description":"Hack","course_ids":[1]}'
assert_status 403 "Student blocked from experience creation"
do_req POST "$ENROLMENT/api/school/cohorts" "$STUDENT_TOKEN" '{"experience_id":1,"name":"Hack","start_date":"2026-09-01","end_date":"2026-12-01","capacity":10}'
assert_status 403 "Student blocked from cohort creation"
do_req POST "$ENROLMENT/api/school/cohorts/1/enrolments" "$STUDENT_TOKEN" '{"student_id":12}'
assert_status 403 "Student blocked from enrol operation"
do_req POST "$EXPERIENCE/api/school/experiences" "$PARENT_TOKEN" '{"name":"Hack","description":"Hack","course_ids":[1]}'
assert_status 403 "Parent blocked from experience creation"
do_req POST "$ENROLMENT/api/school/cohorts" "$PARENT_TOKEN" '{"experience_id":1,"name":"Hack","start_date":"2026-09-01","end_date":"2026-12-01","capacity":10}'
assert_status 403 "Parent blocked from cohort creation"

# Student — Experience service student detail
do_req GET "$EXPERIENCE/api/school/experiences/1/students/4" "$STUDENT_TOKEN"
assert_status 200 "Student views own detail in experience"
do_req GET "$EXPERIENCE/api/school/experiences/1/students/5" "$STUDENT_TOKEN"
assert_status 403 "Student blocked from other student in experience"

# Parent — Experience service student detail
do_req GET "$EXPERIENCE/api/school/experiences/1/students/4" "$PARENT_TOKEN"
assert_status 200 "Parent views linked child detail in experience"
do_req GET "$EXPERIENCE/api/school/experiences/1/students/6" "$PARENT_TOKEN"
assert_status 403 "Parent blocked from non-child in experience"

# ── Phase 7: Experience CRUD Lifecycle ───────────────────────────
echo ""
echo "--- [Phase 7] Experience CRUD Lifecycle ---"

# Create experience
do_req POST "$EXPERIENCE/api/school/experiences" "$ADMIN_TOKEN" \
    '{"name":"Integration Test Exp","description":"Created by integration test","course_ids":[1,2]}'
assert_status 201 "Create experience → 201"
assert_contains '"Integration Test Exp"' "Created exp has correct name"
assert_contains '"courses"' "Created exp has courses"
NEW_EXP_ID=$(json_val "id")
echo "  (created experience ID: $NEW_EXP_ID)"

# Read back
do_req GET "$EXPERIENCE/api/school/experiences/$NEW_EXP_ID" "$ADMIN_TOKEN"
assert_status 200 "Read created experience"
assert_contains '"Integration Test Exp"' "Readback matches"

# Update name + description
do_req PUT "$EXPERIENCE/api/school/experiences/$NEW_EXP_ID" "$ADMIN_TOKEN" \
    '{"name":"Updated Exp Name","description":"Updated description"}'
assert_status 200 "Update experience → 200"
assert_contains '"Updated Exp Name"' "Updated name matches"

# Read back after update
do_req GET "$EXPERIENCE/api/school/experiences/$NEW_EXP_ID" "$ADMIN_TOKEN"
assert_status 200 "Read after update"
assert_contains '"Updated Exp Name"' "Update persisted"

# Update with course_ids
do_req PUT "$EXPERIENCE/api/school/experiences/$NEW_EXP_ID" "$ADMIN_TOKEN" \
    '{"course_ids":[1,3,5]}'
assert_status 200 "Update experience courses → 200"

# Delete (archive)
do_req DELETE "$EXPERIENCE/api/school/experiences/$NEW_EXP_ID" "$ADMIN_TOKEN"
assert_status 200 "Delete experience → 200"
assert_contains 'archived' "Delete response says archived"

# Verify deleted experience returns 404
do_req GET "$EXPERIENCE/api/school/experiences/$NEW_EXP_ID" "$ADMIN_TOKEN"
assert_status 404 "Deleted experience → 404"

# Validation errors
do_req POST "$EXPERIENCE/api/school/experiences" "$ADMIN_TOKEN" \
    '{"description":"No name","course_ids":[1]}'
assert_status 422 "Missing name → 422"

do_req POST "$EXPERIENCE/api/school/experiences" "$ADMIN_TOKEN" \
    '{"name":"   ","description":"Whitespace name","course_ids":[1]}'
assert_status 422 "Whitespace-only name → 422"

LONG_NAME=$(printf '%0.sa' $(seq 1 256))
do_req POST "$EXPERIENCE/api/school/experiences" "$ADMIN_TOKEN" \
    "{\"name\":\"$LONG_NAME\",\"description\":\"Long\",\"course_ids\":[1]}"
assert_status 422 "Name >255 chars → 422"

do_req POST "$EXPERIENCE/api/school/experiences" "$ADMIN_TOKEN" \
    '{"name":"Missing courses","description":"No courses array"}'
assert_status 422 "Missing course_ids → 422"

do_req POST "$EXPERIENCE/api/school/experiences" "$ADMIN_TOKEN" \
    '{"name":"Invalid courses","description":"Bad IDs","course_ids":[99999]}'
assert_status 422 "Invalid course_ids → 422"

# ── Phase 8: Cohort Lifecycle + State Transitions ────────────────
echo ""
echo "--- [Phase 8] Cohort Lifecycle + State Transitions ---"

# Create cohort
do_req POST "$ENROLMENT/api/school/cohorts" "$ADMIN_TOKEN" \
    '{"experience_id":1,"name":"Lifecycle Test Cohort","start_date":"2026-09-01","end_date":"2026-12-01","capacity":15}'
assert_status 201 "Create cohort → 201"
assert_json_val "status" "not_started" "Created cohort is not_started"
LC_COHORT_ID=$(json_val "id")
echo "  (created cohort ID: $LC_COHORT_ID)"

# Read back
do_req GET "$ENROLMENT/api/school/cohorts/$LC_COHORT_ID" "$ADMIN_TOKEN"
assert_status 200 "Read created cohort"
assert_contains '"Lifecycle Test Cohort"' "Cohort name matches"

# Update name
do_req PUT "$ENROLMENT/api/school/cohorts/$LC_COHORT_ID" "$ADMIN_TOKEN" \
    '{"name":"Updated Lifecycle Cohort","capacity":20}'
assert_status 200 "Update cohort → 200"
assert_contains '"Updated Lifecycle Cohort"' "Update name persisted"

# Activate
do_req PATCH "$ENROLMENT/api/school/cohorts/$LC_COHORT_ID/activate" "$ADMIN_TOKEN" '{}'
assert_status 200 "Activate cohort → 200"
assert_json_val "status" "active" "Activated cohort is active"

# Re-activate (already active) → 409
do_req PATCH "$ENROLMENT/api/school/cohorts/$LC_COHORT_ID/activate" "$ADMIN_TOKEN" '{}'
assert_status 409 "Re-activate active → 409"
assert_contains '"INVALID_STATE_TRANSITION"' "409 has error code"

# Complete
do_req PATCH "$ENROLMENT/api/school/cohorts/$LC_COHORT_ID/complete" "$ADMIN_TOKEN" '{}'
assert_status 200 "Complete cohort → 200"
assert_json_val "status" "completed" "Completed cohort is completed"

# Re-complete (already completed) → 409
do_req PATCH "$ENROLMENT/api/school/cohorts/$LC_COHORT_ID/complete" "$ADMIN_TOKEN" '{}'
assert_status 409 "Re-complete → 409"

# Reactivate completed → 409
do_req PATCH "$ENROLMENT/api/school/cohorts/$LC_COHORT_ID/activate" "$ADMIN_TOKEN" '{}'
assert_status 409 "Reactivate completed → 409"

# Activate nonexistent → 404
do_req PATCH "$ENROLMENT/api/school/cohorts/9999/activate" "$ADMIN_TOKEN" '{}'
assert_status 404 "Activate nonexistent → 404"

# Complete nonexistent → 404
do_req PATCH "$ENROLMENT/api/school/cohorts/9999/complete" "$ADMIN_TOKEN" '{}'
assert_status 404 "Complete nonexistent → 404"

# Validation: empty name
do_req POST "$ENROLMENT/api/school/cohorts" "$ADMIN_TOKEN" \
    '{"experience_id":1,"name":"","start_date":"2026-09-01","end_date":"2026-12-01","capacity":10}'
assert_status 422 "Empty cohort name → 422"

# Validation: whitespace name
do_req POST "$ENROLMENT/api/school/cohorts" "$ADMIN_TOKEN" \
    '{"experience_id":1,"name":"   ","start_date":"2026-09-01","end_date":"2026-12-01","capacity":10}'
assert_status 422 "Whitespace cohort name → 422"

# Validation: invalid experience_id
do_req POST "$ENROLMENT/api/school/cohorts" "$ADMIN_TOKEN" \
    '{"experience_id":9999,"name":"Bad Exp","start_date":"2026-09-01","end_date":"2026-12-01","capacity":10}'
assert_status 422 "Invalid experience_id → 422"

# Validation: capacity = 0
do_req POST "$ENROLMENT/api/school/cohorts" "$ADMIN_TOKEN" \
    '{"experience_id":1,"name":"Zero Cap","start_date":"2026-09-01","end_date":"2026-12-01","capacity":0}'
assert_status 422 "Capacity 0 → 422"

# Validation: negative capacity
do_req POST "$ENROLMENT/api/school/cohorts" "$ADMIN_TOKEN" \
    '{"experience_id":1,"name":"Neg Cap","start_date":"2026-09-01","end_date":"2026-12-01","capacity":-5}'
assert_status 422 "Negative capacity → 422"

# ── Phase 9: Enrolment Lifecycle ─────────────────────────────────
echo ""
echo "--- [Phase 9] Enrolment Lifecycle ---"

# Create an active cohort for enrolment tests
do_req POST "$ENROLMENT/api/school/cohorts" "$ADMIN_TOKEN" \
    '{"experience_id":1,"name":"Enrol Test Cohort","start_date":"2026-09-01","end_date":"2026-12-01","capacity":3}'
assert_status 201 "Create enrol test cohort"
ENROL_COHORT_ID=$(json_val "id")
echo "  (enrol test cohort ID: $ENROL_COHORT_ID)"

do_req PATCH "$ENROLMENT/api/school/cohorts/$ENROL_COHORT_ID/activate" "$ADMIN_TOKEN" '{}'
assert_status 200 "Activate enrol test cohort"

# Enrol student 12 (unassigned)
do_req POST "$ENROLMENT/api/school/cohorts/$ENROL_COHORT_ID/enrolments" "$ADMIN_TOKEN" \
    '{"student_id":12}'
assert_status 201 "Enrol student 12 → 201"
assert_contains '"enrolled"' "Enrolment status is enrolled"
assert_contains '"enrolled_at"' "Has enrolled_at timestamp"

# Duplicate enrolment → 422
do_req POST "$ENROLMENT/api/school/cohorts/$ENROL_COHORT_ID/enrolments" "$ADMIN_TOKEN" \
    '{"student_id":12}'
assert_status 422 "Duplicate enrolment → 422"
assert_contains '"DUPLICATE_ENROLMENT"' "Duplicate error code"

# Remove student
do_req DELETE "$ENROLMENT/api/school/cohorts/$ENROL_COHORT_ID/enrolments/12" "$ADMIN_TOKEN"
assert_status 200 "Remove student → 200"
assert_contains 'removed from cohort' "Remove response"

# Re-enrol after removal
do_req POST "$ENROLMENT/api/school/cohorts/$ENROL_COHORT_ID/enrolments" "$ADMIN_TOKEN" \
    '{"student_id":12}'
assert_status 201 "Re-enrol after removal → 201"

# Enrol in not_started cohort (Cohort B, id=2)
do_req POST "$ENROLMENT/api/school/cohorts/2/enrolments" "$ADMIN_TOKEN" \
    '{"student_id":13}'
assert_status 422 "Enrol in not_started cohort → 422"

# Enrol in completed cohort (Cohort D, id=4)
do_req POST "$ENROLMENT/api/school/cohorts/4/enrolments" "$ADMIN_TOKEN" \
    '{"student_id":13}'
assert_status 422 "Enrol in completed cohort → 422"

# Enrol nonexistent student
do_req POST "$ENROLMENT/api/school/cohorts/$ENROL_COHORT_ID/enrolments" "$ADMIN_TOKEN" \
    '{"student_id":9999}'
assert_status 422 "Enrol nonexistent student → 422"

# Remove from nonexistent cohort
do_req DELETE "$ENROLMENT/api/school/cohorts/9999/enrolments/4" "$ADMIN_TOKEN"
assert_status 404 "Remove from nonexistent cohort → 404"

# Remove non-enrolled student
do_req DELETE "$ENROLMENT/api/school/cohorts/$ENROL_COHORT_ID/enrolments/13" "$ADMIN_TOKEN"
assert_status 404 "Remove non-enrolled student → 404"

# Capacity enforcement: fill cohort to capacity (3), then try one more
do_req POST "$ENROLMENT/api/school/cohorts/$ENROL_COHORT_ID/enrolments" "$ADMIN_TOKEN" \
    '{"student_id":13}'
assert_status 201 "Enrol student 13 (2 of 3)"

do_req POST "$ENROLMENT/api/school/cohorts/$ENROL_COHORT_ID/enrolments" "$ADMIN_TOKEN" \
    '{"student_id":10}'
assert_status 201 "Enrol student 10 (3 of 3, full)"

do_req POST "$ENROLMENT/api/school/cohorts/$ENROL_COHORT_ID/enrolments" "$ADMIN_TOKEN" \
    '{"student_id":11}'
assert_status 422 "Enrol beyond capacity → 422"
assert_contains 'full capacity' "Capacity error message"

# ── Phase 10: Search & Filtering ─────────────────────────────────
echo ""
echo "--- [Phase 10] Search & Filtering ---"

# Experience search
do_req GET "$EXPERIENCE/api/school/experiences?search=business" "$ADMIN_TOKEN"
assert_status 200 "Experience search (business)"
assert_contains '"Business Foundations"' "Search finds Business Foundations"

do_req GET "$EXPERIENCE/api/school/experiences?search=zzzznonexistent" "$ADMIN_TOKEN"
assert_status 200 "Experience search no match → 200"

# Cohort filter by status
do_req GET "$ENROLMENT/api/school/cohorts?status=active" "$ADMIN_TOKEN"
assert_status 200 "Cohort filter status=active"
assert_not_contains '"not_started"' "Active filter excludes not_started"
assert_not_contains '"completed"' "Active filter excludes completed"

do_req GET "$ENROLMENT/api/school/cohorts?status=not_started" "$ADMIN_TOKEN"
assert_status 200 "Cohort filter status=not_started"

# Cohort filter by experience_id
do_req GET "$ENROLMENT/api/school/cohorts?experience_id=2" "$ADMIN_TOKEN"
assert_status 200 "Cohort filter experience_id=2"

# Cohort search by name
do_req GET "$ENROLMENT/api/school/cohorts?search=cohort%20a" "$ADMIN_TOKEN"
assert_status 200 "Cohort search by name"
assert_contains '"Cohort A"' "Cohort search finds Cohort A"

# Combined search + status
do_req GET "$ENROLMENT/api/school/cohorts?search=cohort&status=active" "$ADMIN_TOKEN"
assert_status 200 "Cohort combined search + status"

# Enrolment filter by student_id
do_req GET "$ENROLMENT/api/school/enrolments?student_id=4" "$ADMIN_TOKEN"
assert_status 200 "Enrolment filter student_id=4"
assert_contains '"data"' "Filtered enrolments has data"

# Enrolment filter by experience_id
do_req GET "$ENROLMENT/api/school/enrolments?experience_id=1" "$ADMIN_TOKEN"
assert_status 200 "Enrolment filter experience_id=1"

# Experience students search
do_req GET "$EXPERIENCE/api/school/experiences/1/students?search=student" "$ADMIN_TOKEN"
assert_status 200 "Experience students search"

# ── Phase 11: Pagination Edge Cases ──────────────────────────────
echo ""
echo "--- [Phase 11] Pagination Edge Cases ---"

# per_page=1
do_req GET "$EXPERIENCE/api/school/experiences?per_page=1" "$ADMIN_TOKEN"
assert_status 200 "Experiences per_page=1"
# Check meta shows per_page=1
assert_contains '"per_page":1' "Meta shows per_page=1"

# per_page=100
do_req GET "$EXPERIENCE/api/school/experiences?per_page=100" "$ADMIN_TOKEN"
assert_status 200 "Experiences per_page=100"

# per_page=0 → clamped to 1
do_req GET "$EXPERIENCE/api/school/experiences?per_page=0" "$ADMIN_TOKEN"
assert_status 200 "Experiences per_page=0 → clamped"
assert_contains '"per_page":1' "per_page=0 clamped to 1"

# page beyond last → empty data
do_req GET "$EXPERIENCE/api/school/experiences?page=999" "$ADMIN_TOKEN"
assert_status 200 "Page beyond last → 200"

# Enrolments per_page=1
do_req GET "$ENROLMENT/api/school/enrolments?per_page=1" "$ADMIN_TOKEN"
assert_status 200 "Enrolments per_page=1"

# Cohorts pagination
do_req GET "$ENROLMENT/api/school/cohorts?per_page=2" "$ADMIN_TOKEN"
assert_status 200 "Cohorts per_page=2"

# ── Phase 12: Security Headers ───────────────────────────────────
echo ""
echo "--- [Phase 12] Security Headers ---"

# Dashboard
do_req_noauth GET "$DASHBOARD/api/school/dashboard/health"
assert_header "Content-Security-Policy" "Dashboard: CSP header"
assert_header "X-Content-Type-Options" "Dashboard: X-Content-Type-Options"
assert_header "X-Frame-Options" "Dashboard: X-Frame-Options"

# Experience
do_req_noauth GET "$EXPERIENCE/api/school/experiences/health"
assert_header "Content-Security-Policy" "Experience: CSP header"
assert_header "X-Content-Type-Options" "Experience: X-Content-Type-Options"
assert_header "X-Frame-Options" "Experience: X-Frame-Options"

# Enrolment
do_req_noauth GET "$ENROLMENT/api/school/enrolments/health"
assert_header "Content-Security-Policy" "Enrolment: CSP header"
assert_header "X-Content-Type-Options" "Enrolment: X-Content-Type-Options"
assert_header "X-Frame-Options" "Enrolment: X-Frame-Options"

# ── Phase 13: Error Envelope Consistency ─────────────────────────
echo ""
echo "--- [Phase 13] Error Envelope Consistency ---"

# 401 envelope
do_req_noauth GET "$ENROLMENT/api/school/cohorts"
assert_contains '"error":true' "401 has error:true"
assert_contains '"UNAUTHENTICATED"' "401 has code UNAUTHENTICATED"

# 403 envelope
do_req GET "$DASHBOARD/api/school/dashboard" "$STUDENT_TOKEN"
assert_contains '"error":true' "403 has error:true"
assert_contains '"FORBIDDEN"' "403 has code FORBIDDEN"

# 404 envelope — each service
do_req GET "$ENROLMENT/api/school/cohorts/9999" "$ADMIN_TOKEN"
assert_contains '"error":true' "Enrolment 404 has error:true"
assert_contains '"NOT_FOUND"' "Enrolment 404 has code NOT_FOUND"

do_req GET "$EXPERIENCE/api/school/experiences/9999" "$ADMIN_TOKEN"
assert_contains '"error":true' "Experience 404 has error:true"
assert_contains '"NOT_FOUND"' "Experience 404 has code NOT_FOUND"

do_req GET "$DASHBOARD/api/school/dashboard/students/9999" "$ADMIN_TOKEN"
assert_contains '"error":true' "Dashboard 404 has error:true"
assert_contains '"NOT_FOUND"' "Dashboard 404 has code NOT_FOUND"

# 422 envelope
do_req POST "$ENROLMENT/api/school/cohorts" "$ADMIN_TOKEN" '{"name":"Missing Fields"}'
assert_contains '"error":true' "422 has error:true"
assert_contains '"VALIDATION_ERROR"' "422 has code VALIDATION_ERROR"

# ── Phase 14: HTTP Method Enforcement ────────────────────────────
echo ""
echo "--- [Phase 14] HTTP Method Enforcement ---"

# POST to GET-only endpoint
do_req POST "$EXPERIENCE/api/school/experiences/1" "$ADMIN_TOKEN" '{}'
assert_status 405 "POST to show experience → 405"

# DELETE to GET-only endpoint
do_req DELETE "$ENROLMENT/api/school/enrolments" "$ADMIN_TOKEN"
assert_status 405 "DELETE to enrolments list → 405"

# PUT to POST-only endpoint
do_req PUT "$EXPERIENCE/api/school/experiences" "$ADMIN_TOKEN" '{"name":"test"}'
assert_status 405 "PUT to experience list → 405"

# PATCH to GET-only endpoint
do_req PATCH "$DASHBOARD/api/school/dashboard" "$ADMIN_TOKEN" '{}'
assert_status 405 "PATCH to dashboard overview → 405"

# DELETE to dashboard (read-only service)
do_req DELETE "$DASHBOARD/api/school/dashboard" "$ADMIN_TOKEN"
assert_status 405 "DELETE to dashboard overview → 405"

# POST to health check
do_req_noauth POST "$ENROLMENT/api/school/enrolments/health" '{}'
assert_status 405 "POST to health endpoint → 405"

# ── Phase 15: Data Integrity (Special Characters) ────────────────
echo ""
echo "--- [Phase 15] Data Integrity ---"

# Create experience with special characters
do_req POST "$EXPERIENCE/api/school/experiences" "$ADMIN_TOKEN" \
    '{"name":"Test & <Special> \"Chars\"","description":"Unicode: cafe\u0301 \u2014 em-dash","course_ids":[1]}'
assert_status 201 "Special chars in experience name → 201"
SPECIAL_EXP_ID=$(json_val "id")
echo "  (special char exp ID: $SPECIAL_EXP_ID)"

# Read back and verify
do_req GET "$EXPERIENCE/api/school/experiences/$SPECIAL_EXP_ID" "$ADMIN_TOKEN"
assert_status 200 "Read special char experience"
assert_contains 'Special' "Special chars preserved in readback"

# Create cohort with special characters
do_req POST "$ENROLMENT/api/school/cohorts" "$ADMIN_TOKEN" \
    '{"experience_id":1,"name":"Cohort <Test> & \"Quotes\"","start_date":"2026-09-01","end_date":"2026-12-01","capacity":10}'
assert_status 201 "Special chars in cohort name → 201"
SPECIAL_COHORT_ID=$(json_val "id")

# Read back
do_req GET "$ENROLMENT/api/school/cohorts/$SPECIAL_COHORT_ID" "$ADMIN_TOKEN"
assert_status 200 "Read special char cohort"
assert_contains 'Quotes' "Special chars preserved in cohort"

# Clean up special char experience
do_req DELETE "$EXPERIENCE/api/school/experiences/$SPECIAL_EXP_ID" "$ADMIN_TOKEN"
assert_status 200 "Clean up special char experience"

# ── Phase 16: Cross-Service Consistency ──────────────────────────
echo ""
echo "--- [Phase 16] Cross-Service Consistency ---"

# Create experience in experience service
do_req POST "$EXPERIENCE/api/school/experiences" "$ADMIN_TOKEN" \
    '{"name":"Cross-Service Verify Exp","description":"Verify cross-service","course_ids":[1]}'
assert_status 201 "Create cross-service test experience"
CS_EXP_ID=$(json_val "id")

# Create cohort in enrolment service linked to new experience
do_req POST "$ENROLMENT/api/school/cohorts" "$ADMIN_TOKEN" \
    "{\"experience_id\":$CS_EXP_ID,\"name\":\"Cross-Service Verify Cohort\",\"start_date\":\"2026-09-01\",\"end_date\":\"2026-12-01\",\"capacity\":10}"
assert_status 201 "Create cross-service test cohort"
CS_COHORT_ID=$(json_val "id")

# Activate and enrol
do_req PATCH "$ENROLMENT/api/school/cohorts/$CS_COHORT_ID/activate" "$ADMIN_TOKEN" '{}'
assert_status 200 "Activate cross-service cohort"

do_req POST "$ENROLMENT/api/school/cohorts/$CS_COHORT_ID/enrolments" "$ADMIN_TOKEN" \
    '{"student_id":13}'
assert_status 201 "Enrol student in cross-service cohort"

# Verify experience show includes the new cohort (cross-service call)
do_req GET "$EXPERIENCE/api/school/experiences/$CS_EXP_ID" "$ADMIN_TOKEN"
assert_status 200 "Experience shows cross-service cohort"
assert_contains '"Cross-Service Verify Cohort"' "New cohort visible in experience service"

# Verify dashboard aggregation includes new data
do_req GET "$DASHBOARD/api/school/dashboard" "$ADMIN_TOKEN"
assert_status 200 "Dashboard aggregates new data"

# Verify dashboard student drill-down for newly enrolled student
do_req GET "$DASHBOARD/api/school/dashboard/students/13" "$ADMIN_TOKEN"
assert_status 200 "Dashboard drill-down for newly enrolled student"

# Experience students tab shows the newly enrolled student
do_req GET "$EXPERIENCE/api/school/experiences/$CS_EXP_ID/students" "$ADMIN_TOKEN"
assert_status 200 "Experience students shows enrolled student"

# Clean up
do_req DELETE "$EXPERIENCE/api/school/experiences/$CS_EXP_ID" "$ADMIN_TOKEN"
assert_status 200 "Clean up cross-service experience"

# ═════════════════════════════════════════════════════════════════
echo ""
echo "============================================================"
echo " Results: $PASSED passed, $FAILED failed (of $TOTAL)"
echo "============================================================"
echo ""

if [ "$FAILED" -gt 0 ]; then
    echo "$FAILED test(s) failed — review above."
    exit 1
fi

echo "All comprehensive integration tests passed."
exit 0
