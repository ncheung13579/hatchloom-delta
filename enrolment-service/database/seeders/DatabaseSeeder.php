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

        for ($i = 1; $i <= 10; $i++) {
            DB::table('users')->insertOrIgnore([
                'id' => $i + 3,
                'name' => "Student $i",
                'email' => "student{$i}@ridgewood.edu",
                'password' => Hash::make('password'),
                'role' => 'student',
                'school_id' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

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
        // Students 9-10 (user_ids 12-13) are not assigned
    }
}
