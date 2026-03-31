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
                'name' => 'Ms. Patel',
                'email' => 'patel@ridgewood.edu',
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

        $students = [
            ['id' => 4,  'name' => 'Aiden Carter',      'email' => 'acarter@ridgewood.edu',     'grade' => 10],
            ['id' => 5,  'name' => 'Priya Sharma',      'email' => 'psharma@ridgewood.edu',     'grade' => 10],
            ['id' => 6,  'name' => 'Marcus Chen',       'email' => 'mchen@ridgewood.edu',       'grade' => 11],
            ['id' => 7,  'name' => 'Sophia Rodriguez',  'email' => 'srodriguez@ridgewood.edu',  'grade' => 11],
            ['id' => 8,  'name' => 'Ethan Whitfield',   'email' => 'ewhitfield@ridgewood.edu',  'grade' => 9],
            ['id' => 9,  'name' => 'Zara Okafor',       'email' => 'zokafor@ridgewood.edu',     'grade' => 9],
            ['id' => 10, 'name' => 'Liam Petersen',     'email' => 'lpetersen@ridgewood.edu',   'grade' => 12],
            ['id' => 11, 'name' => 'Mia Takahashi',     'email' => 'mtakahashi@ridgewood.edu',  'grade' => 12],
            ['id' => 12, 'name' => 'Noah Bergstrom',    'email' => 'nbergstrom@ridgewood.edu',  'grade' => 8],
            ['id' => 13, 'name' => 'Chloe Washington',  'email' => 'cwashington@ridgewood.edu', 'grade' => 8],
        ];
        foreach ($students as $s) {
            DB::table('users')->insertOrIgnore([
                'id' => $s['id'],
                'name' => $s['name'],
                'email' => $s['email'],
                'password' => Hash::make('password'),
                'role' => 'student',
                'school_id' => 1,
                'grade' => $s['grade'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Parent user — linked to students via parent_student_links (many-to-many)
        DB::table('users')->insertOrIgnore([
            'id' => 14,
            'name' => 'David Carter',
            'email' => 'dcarter@ridgewood.edu',
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

        // Seed experiences
        DB::table('experiences')->insertOrIgnore([
            [
                'id' => 1,
                'school_id' => 1,
                'name' => 'Business Foundations',
                'description' => 'Introduction to business concepts through Hatchloom courses',
                'status' => 'active',
                'grade' => 10,
                'total_credits' => 12,
                'created_by' => 2,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 2,
                'school_id' => 1,
                'name' => 'Tech Explorers',
                'description' => 'Technology exploration and digital skills development',
                'status' => 'active',
                'grade' => 11,
                'total_credits' => 8,
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
                'grade' => 9,
                'total_credits' => 10,
                'created_by' => 3,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        // Business Foundations has courses 1, 2, 3
        DB::table('experience_courses')->insertOrIgnore([
            ['id' => 1, 'experience_id' => 1, 'course_id' => 1, 'sequence' => 1],
            ['id' => 2, 'experience_id' => 1, 'course_id' => 2, 'sequence' => 2],
            ['id' => 3, 'experience_id' => 1, 'course_id' => 3, 'sequence' => 3],
        ]);

        // Tech Explorers has courses 4, 5
        DB::table('experience_courses')->insertOrIgnore([
            ['id' => 4, 'experience_id' => 2, 'course_id' => 4, 'sequence' => 1],
            ['id' => 5, 'experience_id' => 2, 'course_id' => 5, 'sequence' => 2],
        ]);

        // Creative Problem Solving has courses 2, 5
        DB::table('experience_courses')->insertOrIgnore([
            ['id' => 6, 'experience_id' => 3, 'course_id' => 2, 'sequence' => 1],
            ['id' => 7, 'experience_id' => 3, 'course_id' => 5, 'sequence' => 2],
        ]);

        // Reset PostgreSQL sequences so the next INSERT uses the correct ID.
        // Without this, the first create after seeding fails with a duplicate
        // key violation because the sequence is still at 1 while rows with
        // explicit IDs already exist.
        DB::statement("SELECT setval('schools_id_seq', (SELECT COALESCE(MAX(id), 0) FROM schools))");
        DB::statement("SELECT setval('users_id_seq', (SELECT COALESCE(MAX(id), 0) FROM users))");
        DB::statement("SELECT setval('experiences_id_seq', (SELECT COALESCE(MAX(id), 0) FROM experiences))");
        DB::statement("SELECT setval('experience_courses_id_seq', (SELECT COALESCE(MAX(id), 0) FROM experience_courses))");
    }
}
