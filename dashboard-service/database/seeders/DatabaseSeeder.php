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
    }
}
