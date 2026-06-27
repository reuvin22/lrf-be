<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SystemConfigSeeder extends Seeder
{
    public function run(): void
    {
        $configs = [
            [
                'key' => 'break_deduction_tier1_threshold',
                'value' => 4,
                'description' => 'Upper limit for no break deduction (hours)',
            ],
            [
                'key' => 'break_deduction_tier1_minutes',
                'value' => 0,
                'description' => 'Up to 4h: no deduction',
            ],
            [
                'key' => 'break_deduction_tier2_threshold',
                'value' => 8,
                'description' => 'Upper limit for 45-min deduction (hours)',
            ],
            [
                'key' => 'break_deduction_tier2_minutes',
                'value' => 45,
                'description' => 'Over 4h up to 8h: 45-min deduction',
            ],
            [
                'key' => 'break_deduction_tier3_minutes',
                'value' => 90,
                'description' => 'Over 8h: 90-min deduction',
            ],
            [
                'key' => 'standard_work_hours',
                'value' => 8,
                'description' => 'Prescribed daily work hours (h/day)',
            ],
            [
                'key' => 'overtime_rate',
                'value' => 1.25,
                'description' => 'Standard overtime multiplier',
            ],
            [
                'key' => 'night_rate',
                'value' => 1.50,
                'description' => 'Late-night multiplier (after 22:00)',
            ],
            [
                'key' => 'legal_holiday_rate',
                'value' => 1.35,
                'description' => 'Statutory holiday multiplier',
            ],
            [
                'key' => 'scheduled_holiday_rate',
                'value' => 1.25,
                'description' => 'Prescribed holiday multiplier',
            ],
            [
                'key' => 'night_overtime_rate',
                'value' => 1.50,
                'description' => 'Late-night + overtime multiplier',
            ],
            [
                'key' => 'subcontractor_standard_hours',
                'value' => 8,
                'description' => 'Standard hours for quasi-delegation contracts',
            ],
            [
                'key' => 'subcontractor_overtime_rate',
                'value' => 1.25,
                'description' => 'Overtime multiplier for quasi-delegation contracts',
            ],
            [
                'key' => 'subcontractor_default_start',
                'value' => '09:00',
                'description' => 'Default start time for quasi-delegation contracts',
            ],
            [
                'key' => 'work_start_earliest',
                'value' => '09:00',
                'description' => 'Earliest allowed work start time',
            ],
            [
                'key' => 'closing_day',
                'value' => 10,
                'description' => 'Monthly closing day (day of the following month)',
            ],
        ];

        foreach ($configs as $config) {
            DB::table('system_settings')->updateOrInsert(
                ['key' => $config['key']],
                [
                    'system_settings_id' => (string) Str::uuid(),
                    'value' => $config['value'],
                    'description' => $config['description'],
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }
    }
}