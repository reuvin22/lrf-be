<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DayTypeSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('day_types')->insert([
            [
                'value' => 'WORKDAY',
                'description' => 'Weekday',
                'overtime_multiplier' => 1.25,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'value' => 'HOLIDAY',
                'description' => 'Prescribed holiday (e.g., Saturday)',
                'overtime_multiplier' => 1.25,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'value' => 'LEGAL_HOLIDAY',
                'description' => 'Statutory holiday (Sunday)',
                'overtime_multiplier' => 1.35,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'value' => 'NATIONAL_HOLIDAY',
                'description' => 'National holiday',
                'overtime_multiplier' => 1.35,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}