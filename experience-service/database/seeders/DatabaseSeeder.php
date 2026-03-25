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

        // Parent user — linked to Student 1 (user_id 4) for Team Romeo's parent dashboard
        DB::table('users')->insertOrIgnore([
            'id' => 14,
            'name' => 'Parent of Student 1',
            'email' => 'parent1@ridgewood.edu',
            'password' => Hash::make('password'),
            'role' => 'parent',
            'school_id' => 1,
            'parent_of' => 4,
            'created_at' => now(),
            'updated_at' => now(),
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
