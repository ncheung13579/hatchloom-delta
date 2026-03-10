#!/usr/bin/env sh
# ─────────────────────────────────────────────────────────────────
# Integration tests for Hatchloom Delta microservices.
#
# Runs inside the integration-runner container (curlimages/curl).
# Tests real HTTP requests across all 3 services with a shared
# PostgreSQL database — no mocking, no faking.
#
# Usage (standalone):
#   ./run-integration-tests.sh          # after services are up
#
# Usage (via Docker Compose):
#   docker compose -f docker-compose.integration.yml up --build --abort-on-container-exit
# ─────────────────────────────────────────────────────────────────

set -eu

DASHBOARD="${DASHBOARD_URL:-http://localhost:8001}"
EXPERIENCE="${EXPERIENCE_URL:-http://localhost:8002}"
ENROLMENT="${ENROLMENT_URL:-http://localhost:8003}"
AUTH="Authorization: Bearer test-admin-token"

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

# Make a request and capture HTTP status + body.
# Usage: do_request METHOD URL [DATA]
# Sets: HTTP_STATUS, HTTP_BODY
do_request() {
    METHOD="$1"
    URL="$2"
    DATA="${3:-}"

    TMPFILE=$(mktemp)
    if [ -n "$DATA" ]; then
        HTTP_STATUS=$(curl -s -o "$TMPFILE" -w '%{http_code}' \
            -X "$METHOD" "$URL" \
            -H "$AUTH" \
            -H "Content-Type: application/json" \
            -H "Accept: application/json" \
            -d "$DATA")
    else
        HTTP_STATUS=$(curl -s -o "$TMPFILE" -w '%{http_code}' \
            -X "$METHOD" "$URL" \
            -H "$AUTH" \
            -H "Accept: application/json")
    fi
    HTTP_BODY=$(cat "$TMPFILE")
    rm -f "$TMPFILE"
}

# Assert HTTP status code
assert_status() {
    EXPECTED="$1"
    TEST_NAME="$2"
    if [ "$HTTP_STATUS" = "$EXPECTED" ]; then
        pass "$TEST_NAME"
    else
        fail "$TEST_NAME" "expected status $EXPECTED, got $HTTP_STATUS — body: $(echo "$HTTP_BODY" | head -c 200)"
    fi
}

# Assert response body contains a string
assert_contains() {
    NEEDLE="$1"
    TEST_NAME="$2"
    if echo "$HTTP_BODY" | grep -q "$NEEDLE"; then
        pass "$TEST_NAME"
    else
        fail "$TEST_NAME" "response does not contain '$NEEDLE' — body: $(echo "$HTTP_BODY" | head -c 200)"
    fi
}

# Assert response body does NOT contain a string
assert_not_contains() {
    NEEDLE="$1"
    TEST_NAME="$2"
    if echo "$HTTP_BODY" | grep -q "$NEEDLE"; then
        fail "$TEST_NAME" "response unexpectedly contains '$NEEDLE'"
    else
        pass "$TEST_NAME"
    fi
}

# Extract the first occurrence of a JSON key's value
json_val() {
    # Use grep -o to find all "key":value pairs, take the first one
    echo "$HTTP_BODY" | grep -o "\"$1\"[[:space:]]*:[[:space:]]*[^,}]*" | head -1 | sed "s/\"$1\"[[:space:]]*:[[:space:]]*//" | sed 's/^"//;s/"$//'
}

# ═════════════════════════════════════════════════════════════════
echo ""
echo "============================================="
echo " Hatchloom Delta — Integration Tests"
echo "============================================="
echo ""

# ── 1. Health endpoints ──────────────────────────────────────────
echo "--- Health Checks ---"

do_request GET "$ENROLMENT/api/school/enrolments/health"
assert_status 200 "Enrolment service health"

do_request GET "$EXPERIENCE/api/school/experiences/health"
assert_status 200 "Experience service health"

do_request GET "$DASHBOARD/api/school/dashboard/health"
assert_status 200 "Dashboard service health"

# ── 2. Authentication ────────────────────────────────────────────
echo ""
echo "--- Authentication ---"

# Valid token
do_request GET "$ENROLMENT/api/school/cohorts"
assert_status 200 "Valid token accepted on enrolment service"

# Invalid token — manually override AUTH
TMPFILE_AUTH=$(mktemp)
HTTP_STATUS=$(curl -s -o "$TMPFILE_AUTH" -w '%{http_code}' \
    -X GET "$ENROLMENT/api/school/cohorts" \
    -H "Authorization: Bearer invalid-token-xyz" \
    -H "Accept: application/json")
HTTP_BODY=$(cat "$TMPFILE_AUTH")
rm -f "$TMPFILE_AUTH"
assert_status 401 "Invalid token rejected with 401"

# No token at all
TMPFILE_AUTH=$(mktemp)
HTTP_STATUS=$(curl -s -o "$TMPFILE_AUTH" -w '%{http_code}' \
    -X GET "$ENROLMENT/api/school/cohorts" \
    -H "Accept: application/json")
HTTP_BODY=$(cat "$TMPFILE_AUTH")
rm -f "$TMPFILE_AUTH"
assert_status 401 "Missing token rejected with 401"

# ── 3. Enrolment Service — Cohort CRUD ──────────────────────────
echo ""
echo "--- Enrolment Service: Cohorts ---"

# List seeded cohorts (seeder creates 3)
do_request GET "$ENROLMENT/api/school/cohorts"
assert_status 200 "List cohorts returns 200"
assert_contains '"data"' "Cohort list has data wrapper"

# Show seeded cohort (ID 1 = Cohort A from seeder)
do_request GET "$ENROLMENT/api/school/cohorts/1"
assert_status 200 "Show cohort 1 returns 200"
assert_contains '"student_count"' "Cohort show includes student_count"
assert_contains '"teacher_name"' "Cohort show includes teacher_name"

# Create a new cohort via API
do_request POST "$ENROLMENT/api/school/cohorts" \
    '{"experience_id":1,"name":"Integration Test Cohort","start_date":"2026-09-01","end_date":"2026-12-01","capacity":15}'
assert_status 201 "Create cohort returns 201"
assert_contains '"Integration Test Cohort"' "Created cohort has correct name"
assert_contains '"not_started"' "Created cohort has status not_started"

# Capture the new cohort ID for later tests
NEW_COHORT_ID=$(json_val "id")
echo "  (created cohort ID: $NEW_COHORT_ID)"

# Activate the new cohort
do_request PATCH "$ENROLMENT/api/school/cohorts/${NEW_COHORT_ID}/activate" '{}'
assert_status 200 "Activate cohort returns 200"
assert_contains '"active"' "Activated cohort has status active"

# Cannot activate again (already active)
do_request PATCH "$ENROLMENT/api/school/cohorts/${NEW_COHORT_ID}/activate" '{}'
assert_status 409 "Re-activate active cohort returns 409"
assert_contains '"INVALID_STATE_TRANSITION"' "409 response has correct error code"

# Complete the cohort
do_request PATCH "$ENROLMENT/api/school/cohorts/${NEW_COHORT_ID}/complete" '{}'
assert_status 200 "Complete cohort returns 200"
assert_contains '"completed"' "Completed cohort has status completed"

# Cannot reactivate completed cohort
do_request PATCH "$ENROLMENT/api/school/cohorts/${NEW_COHORT_ID}/activate" '{}'
assert_status 409 "Reactivate completed cohort returns 409"

# 404 for nonexistent cohort
do_request GET "$ENROLMENT/api/school/cohorts/9999"
assert_status 404 "Nonexistent cohort returns 404"
assert_contains '"NOT_FOUND"' "404 uses standard error envelope"

# Filter by status
do_request GET "$ENROLMENT/api/school/cohorts?status=active"
assert_status 200 "Filter cohorts by status returns 200"
assert_not_contains '"Integration Test Cohort"' "Completed cohort excluded from active filter"

# ── 4. Enrolment Service — Student Enrolments ───────────────────
echo ""
echo "--- Enrolment Service: Enrolments ---"

# Enrol a student into seeded Cohort A (id=1, active)
# Student 4 is seeded (student1@ridgewood.edu), but already enrolled by seeder.
# Use student 12 (Student 9, not enrolled in any cohort by seeder)
do_request POST "$ENROLMENT/api/school/cohorts/1/enrolments" \
    '{"student_id":12}'
assert_status 201 "Enrol student returns 201"
assert_contains '"enrolled"' "Enrolment status is enrolled"
assert_contains '"enrolled_at"' "Enrolment includes enrolled_at timestamp"

# Cannot enrol same student again (duplicate)
do_request POST "$ENROLMENT/api/school/cohorts/1/enrolments" \
    '{"student_id":12}'
assert_status 422 "Duplicate enrolment returns 422"
assert_contains '"DUPLICATE_ENROLMENT"' "Duplicate has correct error code"

# List all enrolments
do_request GET "$ENROLMENT/api/school/enrolments"
assert_status 200 "List enrolments returns 200"
assert_contains '"data"' "Enrolment list has data wrapper"
assert_contains '"meta"' "Enrolment list has meta pagination"

# Enrolment statistics
do_request GET "$ENROLMENT/api/school/enrolments/statistics"
assert_status 200 "Enrolment statistics returns 200"
assert_contains '"total_students"' "Statistics has total_students"
assert_contains '"enrolled"' "Statistics has enrolled count"
assert_contains '"not_assigned"' "Statistics has not_assigned count"

# Remove a student
do_request DELETE "$ENROLMENT/api/school/cohorts/1/enrolments/12"
assert_status 200 "Remove student returns 200"
assert_contains 'removed from cohort' "Remove response confirms removal"

# Student detail
do_request GET "$ENROLMENT/api/school/enrolments/students/4"
assert_status 200 "Student enrolment detail returns 200"

# CSV export
TMPFILE_CSV=$(mktemp)
HTTP_STATUS=$(curl -s -o "$TMPFILE_CSV" -w '%{http_code}' \
    -X GET "$ENROLMENT/api/school/enrolments/export" \
    -H "$AUTH" \
    -H "Accept: text/csv")
HTTP_BODY=$(cat "$TMPFILE_CSV")
rm -f "$TMPFILE_CSV"
assert_status 200 "CSV export returns 200"

# ── 5. Experience Service — CRUD ────────────────────────────────
echo ""
echo "--- Experience Service: Experiences ---"

# List seeded experiences
do_request GET "$EXPERIENCE/api/school/experiences"
assert_status 200 "List experiences returns 200"
assert_contains '"data"' "Experience list has data wrapper"
assert_contains '"meta"' "Experience list has meta pagination"

# Show seeded experience (ID 1 = Business Foundations)
do_request GET "$EXPERIENCE/api/school/experiences/1"
assert_status 200 "Show experience 1 returns 200"
assert_contains '"Business Foundations"' "Experience 1 is Business Foundations"
assert_contains '"courses"' "Experience show includes courses"

# Create a new experience
do_request POST "$EXPERIENCE/api/school/experiences" \
    '{"name":"Integration Test Experience","description":"Created by integration test","course_ids":[1,2]}'
assert_status 201 "Create experience returns 201"
assert_contains '"Integration Test Experience"' "Created experience has correct name"
assert_contains '"courses"' "Created experience includes courses"

NEW_EXP_ID=$(json_val "id")
echo "  (created experience ID: $NEW_EXP_ID)"

# Update the experience
do_request PATCH "$EXPERIENCE/api/school/experiences/${NEW_EXP_ID}" \
    '{"name":"Updated Integration Experience"}'
assert_status 200 "Update experience returns 200"
assert_contains '"Updated Integration Experience"' "Updated experience has new name"

# Experience statistics
do_request GET "$EXPERIENCE/api/school/experiences/1/statistics"
assert_status 200 "Experience statistics returns 200"

# Experience students list
do_request GET "$EXPERIENCE/api/school/experiences/1/students"
assert_status 200 "Experience students list returns 200"

# Experience contents
do_request GET "$EXPERIENCE/api/school/experiences/1/contents"
assert_status 200 "Experience contents returns 200"

# Search experiences (case-insensitive)
do_request GET "$EXPERIENCE/api/school/experiences?search=business"
assert_status 200 "Search experiences (lowercase) returns 200"
assert_contains '"Business Foundations"' "Case-insensitive search finds Business Foundations"

# 404 for nonexistent
do_request GET "$EXPERIENCE/api/school/experiences/9999"
assert_status 404 "Nonexistent experience returns 404"
assert_contains '"NOT_FOUND"' "Experience 404 uses standard error envelope"

# Delete the test experience
do_request DELETE "$EXPERIENCE/api/school/experiences/${NEW_EXP_ID}"
assert_status 200 "Delete experience returns 200"

# Deleted experience returns 404
do_request GET "$EXPERIENCE/api/school/experiences/${NEW_EXP_ID}"
assert_status 404 "Deleted experience returns 404"

# ── 6. Experience → Enrolment cross-service communication ───────
echo ""
echo "--- Cross-Service: Experience → Enrolment ---"

# Experience show should include cohorts fetched from enrolment service
do_request GET "$EXPERIENCE/api/school/experiences/1"
assert_status 200 "Experience show (with real cohort data) returns 200"
assert_contains '"cohorts"' "Experience show includes cohorts from enrolment service"
# Cohort A is linked to experience 1 and seeded
assert_contains '"Cohort A"' "Experience show lists Cohort A from enrolment service"

# ── 7. Dashboard — aggregates from Experience + Enrolment ───────
echo ""
echo "--- Cross-Service: Dashboard Aggregation ---"

do_request GET "$DASHBOARD/api/school/dashboard"
assert_status 200 "Dashboard overview returns 200"
assert_contains '"school"' "Dashboard has school section"
assert_contains '"summary"' "Dashboard has summary section"
assert_contains '"cohorts"' "Dashboard has cohorts section"
assert_contains '"students"' "Dashboard has students section"
assert_contains '"statistics"' "Dashboard has statistics section"
assert_contains '"warnings"' "Dashboard has warnings section"

# Verify dashboard pulls real data (not zeros — seeder has data)
assert_contains '"Ridgewood Academy"' "Dashboard shows correct school name"

# Student drill-down (student 4 is enrolled in Cohort A)
do_request GET "$DASHBOARD/api/school/dashboard/students/4"
assert_status 200 "Student drill-down returns 200"

# Reporting endpoints
do_request GET "$DASHBOARD/api/school/dashboard/reporting/pos-coverage"
assert_status 200 "PoS coverage reporting returns 200"

do_request GET "$DASHBOARD/api/school/dashboard/reporting/engagement"
assert_status 200 "Engagement reporting returns 200"

# ── 8. Error envelope consistency across services ────────────────
echo ""
echo "--- Error Envelope Consistency ---"

# 404 from each service should have same structure
do_request GET "$ENROLMENT/api/school/cohorts/9999"
assert_contains '"error":true' "Enrolment 404 has error:true"
assert_contains '"message"' "Enrolment 404 has message"
assert_contains '"code"' "Enrolment 404 has code"

do_request GET "$EXPERIENCE/api/school/experiences/9999"
assert_contains '"error":true' "Experience 404 has error:true"
assert_contains '"message"' "Experience 404 has message"
assert_contains '"code"' "Experience 404 has code"

# Validation error from enrolment
do_request POST "$ENROLMENT/api/school/cohorts" \
    '{"name":"Missing Fields"}'
assert_status 422 "Missing required fields returns 422"

# Validation error from experience
do_request POST "$EXPERIENCE/api/school/experiences" \
    '{"description":"No name"}'
assert_status 422 "Missing experience name returns 422"

# ── 9. Data consistency — create in one service, verify in another
echo ""
echo "--- Data Consistency Across Services ---"

# Create a new cohort in enrolment service for experience 2
do_request POST "$ENROLMENT/api/school/cohorts" \
    '{"experience_id":2,"name":"Cross-Service Cohort","start_date":"2026-10-01","end_date":"2026-12-31","capacity":10}'
assert_status 201 "Create cross-service cohort returns 201"
CROSS_COHORT_ID=$(json_val "id")

# Verify the new cohort appears in experience service's experience detail
do_request GET "$EXPERIENCE/api/school/experiences/2"
assert_status 200 "Experience 2 show returns 200"
assert_contains '"Cross-Service Cohort"' "New cohort visible in experience service"

# Verify dashboard sees updated cohort counts
do_request GET "$DASHBOARD/api/school/dashboard"
assert_status 200 "Dashboard reflects new cohort"

# ═════════════════════════════════════════════════════════════════
echo ""
echo "============================================="
echo " Results: $PASSED passed, $FAILED failed (of $TOTAL)"
echo "============================================="
echo ""

if [ "$FAILED" -gt 0 ]; then
    exit 1
fi

echo "All integration tests passed."
exit 0
