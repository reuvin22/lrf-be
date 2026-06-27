<?php

namespace Database\Seeders;

use App\Models\SiteAssignments;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class SiteAssignmentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        SiteAssignments::create([
            'assignment_id' => (string) Str::uuid(),
            'employee_id' => "411369bf-5696-470e-b3a3-d17031fd3e1f",
            'site_id' => "324c7b7d-f69d-46c7-9714-ec4a728505ef",
            'is_leader' => true,
            'start_date' => now()->subDays(10),
            'end_date' => now()->addMonths(1),
        ]);

        SiteAssignments::create([
            'assignment_id' => (string) Str::uuid(),
            'employee_id' => "411369bf-5696-470e-b3a3-d17031fd3e1f",
            'site_id' => "1f069f4b-a605-4ea7-8151-d7612b46e4ab",
            'is_leader' => false,
            'start_date' => now()->subDays(5),
            'end_date' => now()->addMonths(2),
        ]);
    }
}
