<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SubcontractorSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('subcontractors')->insert([
            [
                'subcontractor_id' => (string) Str::uuid(),
                'company_name' => 'MaruTech Electrical',
                'contact_person' => 'Hiroshi Tanaka',
                'contact_phone' => '090-1234-5678',
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'subcontractor_id' => (string) Str::uuid(),
                'company_name' => 'Sakura Construction',
                'contact_person' => 'Yuki Suzuki',
                'contact_phone' => '090-2345-6789',
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'subcontractor_id' => (string) Str::uuid(),
                'company_name' => 'Kansai Steel Works',
                'contact_person' => 'Kenji Yamamoto',
                'contact_phone' => '090-3456-7890',
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'subcontractor_id' => (string) Str::uuid(),
                'company_name' => 'Tohoku Pipe Engineering',
                'contact_person' => 'Naoki Sato',
                'contact_phone' => '090-4567-8901',
                'status' => 'TERMINATED',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'subcontractor_id' => (string) Str::uuid(),
                'company_name' => 'Fuji Interior Systems',
                'contact_person' => 'Aya Nakamura',
                'contact_phone' => '090-5678-9012',
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}
