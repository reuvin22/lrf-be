<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class EmployeeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::table('employees')->insert([
            [
                'employee_code' => 'EMP001',
                'name' => 'Taro Yamada',
                'name_kana' => 'タロウ ヤマダ',
                'line_user_id' => null,
                'email' => 'taro.yamada@example.com',
                'employment_type' => 'FULL_TIME',
                'role' => 'GENERAL',
                'base_salary' => 300000,
                'monthly_work_hours' => 160.00,
                'cost_rate' => 1,
                'commute_cost_monthly' => 10000,
                'joined_date' => '2023-01-10',
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'employee_code' => 'EMP002',
                'name' => 'Hanako Suzuki',
                'name_kana' => 'ハナコ スズキ',
                'line_user_id' => null,
                'email' => 'hanako.suzuki@example.com',
                'employment_type' => 'FULL_TIME',
                'role' => 'ADMIN',
                'base_salary' => 350000,
                'monthly_work_hours' => 160.00,
                'cost_rate' => 1,
                'commute_cost_monthly' => 12000,
                'joined_date' => '2022-08-15',
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'employee_code' => 'EMP003',
                'name' => 'Kenji Tanaka',
                'name_kana' => 'ケンジ タナカ',
                'line_user_id' => null,
                'email' => 'kenji.tanaka@example.com',
                'employment_type' => 'PART_TIME',
                'role' => 'GENERAL',
                'base_salary' => 150000,
                'monthly_work_hours' => 80.00,
                'cost_rate' => 1,
                'commute_cost_monthly' => 5000,
                'joined_date' => '2024-03-01',
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'employee_code' => 'EMP004',
                'name' => 'Yuki Nakamura',
                'name_kana' => 'ユキ ナカムラ',
                'line_user_id' => null,
                'email' => 'yuki.nakamura@example.com',
                'employment_type' => 'CONTRACT',
                'role' => 'ACCOUNTING',
                'base_salary' => 250000,
                'monthly_work_hours' => 140.00,
                'cost_rate' => 1,
                'commute_cost_monthly' => 8000,
                'joined_date' => '2023-06-20',
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'employee_code' => 'EMP005',
                'name' => 'Satoshi Ito',
                'name_kana' => 'サトシ イトウ',
                'line_user_id' => null,
                'email' => 'satoshi.ito@example.com',
                'employment_type' => 'FULL_TIME',
                'role' => 'GENERAL',
                'base_salary' => 320000,
                'monthly_work_hours' => 160.00,
                'cost_rate' => 1,
                'commute_cost_monthly' => 10000,
                'joined_date' => '2024-01-05',
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}
