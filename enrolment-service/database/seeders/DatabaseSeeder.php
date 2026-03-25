<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('schools')->insertOrIgnore([
            'id' => 1,
            'name' => 'Ridgewood Academy',
            'code' => 'RIDGE',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('users')->insertOrIgnore([
            [
                'id' => 1,
                'name' => 'Admin User',
                'email' => 'admin@ridgewood.edu',
                'password' => Hash::make('password'),
                'role' => 'school_admin',
                'school_id' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 2,
                'name' => 'Ms. Smith',
                'email' => 'teacher1@ridgewood.edu',
                'password' => Hash::make('password'),
                'role' => 'school_teacher',
                'school_id' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 3,
                'name' => 'Mr. Johnson',
                'email' => 'teacher2@ridgewood.edu',
                'password' => Hash::make('password'),
                'role' => 'school_teacher',
                'school_id' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $grades = [10, 10, 11, 11, 9, 9, 12, 12, 8, 8];
        for ($i = 1; $i <= 10; $i++) {
            DB::table('users')->insertOrIgnore([
                'id' => $i + 3,
                'name' => "Student $i",
                'email' => "student{$i}@ridgewood.edu",
                'password' => Hash::make('password'),
                'role' => 'student',
                'school_id' => 1,
                'grade' => $grades[$i - 1],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Parent user — linked to students via parent_student_links (many-to-many)
        DB::table('users')->insertOrIgnore([
            'id' => 14,
            'name' => 'Parent of Student 1',
            'email' => 'parent1@ridgewood.edu',
            'password' => Hash::make('password'),
            'role' => 'parent',
            'school_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Parent-student links (canonical many-to-many per Karl's Role B workpack)
        DB::table('parent_student_links')->insertOrIgnore([
            ['parent_id' => 14, 'student_id' => 4],  // Parent 1 -> Student 1
            ['parent_id' => 14, 'student_id' => 5],  // Parent 1 -> Student 2
        ]);

        // Platform-level roles (no school_id — belong to Hatchloom, not a school)
        DB::table('users')->insertOrIgnore([
            'id' => 15,
            'name' => 'Hatchloom Course Builder',
            'email' => 'teacher@hatchloom.com',
            'password' => Hash::make('password'),
            'role' => 'hatchloom_teacher',
            'school_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('users')->insertOrIgnore([
            'id' => 16,
            'name' => 'Hatchloom Platform Admin',
            'email' => 'admin@hatchloom.com',
            'password' => Hash::make('password'),
            'role' => 'hatchloom_admin',
            'school_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Seed experiences (reference data for cohorts FK)
        DB::table('experiences')->insertOrIgnore([
            [
                'id' => 1,
                'school_id' => 1,
                'name' => 'Business Foundations',
                'description' => 'Introduction to business concepts',
                'status' => 'active',
                'created_by' => 2,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 2,
                'school_id' => 1,
                'name' => 'Tech Explorers',
                'description' => 'Technology exploration',
                'status' => 'active',
                'created_by' => 2,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 3,
                'school_id' => 1,
                'name' => 'Creative Problem Solving',
                'description' => 'Design thinking and collaborative problem-solving workshops',
                'status' => 'draft',
                'created_by' => 3,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        // Seed cohorts
        DB::table('cohorts')->insertOrIgnore([
            [
                'id' => 1,
                'experience_id' => 1,
                'school_id' => 1,
                'name' => 'Cohort A',
                'status' => 'active',
                'teacher_id' => 2,
                'capacity' => 25,
                'start_date' => '2026-02-01',
                'end_date' => '2026-06-01',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 2,
                'experience_id' => 1,
                'school_id' => 1,
                'name' => 'Cohort B',
                'status' => 'not_started',
                'teacher_id' => 3,
                'capacity' => 20,
                'start_date' => '2026-04-01',
                'end_date' => '2026-08-01',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 3,
                'experience_id' => 2,
                'school_id' => 1,
                'name' => 'Cohort C',
                'status' => 'active',
                'teacher_id' => 2,
                'capacity' => 15,
                'start_date' => '2026-02-01',
                'end_date' => '2026-06-01',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 4,
                'experience_id' => 1,
                'school_id' => 1,
                'name' => 'Cohort D',
                'status' => 'completed',
                'teacher_id' => 3,
                'capacity' => 20,
                'start_date' => '2025-09-01',
                'end_date' => '2025-12-15',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        // Enrol students 1-6 (user_ids 4-9) in Cohort A
        for ($i = 1; $i <= 6; $i++) {
            DB::table('cohort_enrolments')->insertOrIgnore([
                'cohort_id' => 1,
                'student_id' => $i + 3,
                'status' => 'enrolled',
                'enrolled_at' => now(),
            ]);
        }

        // Enrol students 7-8 (user_ids 10-11) in Cohort C
        for ($i = 7; $i <= 8; $i++) {
            DB::table('cohort_enrolments')->insertOrIgnore([
                'cohort_id' => 3,
                'student_id' => $i + 3,
                'status' => 'enrolled',
                'enrolled_at' => now(),
            ]);
        }
        // Cross-cohort: Student 3 (user_id 6) also in Cohort C — demonstrates
        // students appearing in multiple cohorts across different experiences
        DB::table('cohort_enrolments')->insertOrIgnore([
            'cohort_id' => 3,
            'student_id' => 6,
            'status' => 'enrolled',
            'enrolled_at' => now(),
        ]);

        // Cohort D (completed) — students 1-4 (user_ids 4-7) completed this cohort
        for ($i = 1; $i <= 4; $i++) {
            DB::table('cohort_enrolments')->insertOrIgnore([
                'cohort_id' => 4,
                'student_id' => $i + 3,
                'status' => 'enrolled',
                'enrolled_at' => now()->subMonths(4),
            ]);
        }

        // Students 9-10 (user_ids 12-13) are not assigned

        // Reset PostgreSQL sequences so the next INSERT uses the correct ID.
        // Without this, the first create after seeding fails with a duplicate
        // key violation because the sequence is still at 1 while rows with
        // explicit IDs already exist.
        DB::statement("SELECT setval('schools_id_seq', (SELECT COALESCE(MAX(id), 0) FROM schools))");
        DB::statement("SELECT setval('users_id_seq', (SELECT COALESCE(MAX(id), 0) FROM users))");
        DB::statement("SELECT setval('experiences_id_seq', (SELECT COALESCE(MAX(id), 0) FROM experiences))");
        DB::statement("SELECT setval('cohorts_id_seq', (SELECT COALESCE(MAX(id), 0) FROM cohorts))");
        DB::statement("SELECT setval('cohort_enrolments_id_seq', (SELECT COALESCE(MAX(id), 0) FROM cohort_enrolments))");
    }
}
