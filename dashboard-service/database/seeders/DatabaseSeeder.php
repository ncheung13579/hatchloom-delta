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
    }
}
