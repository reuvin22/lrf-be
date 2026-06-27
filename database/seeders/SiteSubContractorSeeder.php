<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SiteSubContractorSeeder extends Seeder
{
    public function run(): void
    {
        // ✅ Your real site UUIDs
        $sites = [
            'SIT-001' => '1f069f4b-a605-4ea7-8151-d7612b46e4ab',
            'SIT-002' => '324c7b7d-f69d-46c7-9714-ec4a728505ef',
            'SIT-003' => '1d069608-9b46-4bfa-a130-0d81295aa350',
        ];

        // ✅ Get subcontractor UUIDs dynamically
        $subcontractors = DB::table('subcontractors')
            ->pluck('subcontractor_id')
            ->values();

        $rows = [];

        foreach ($subcontractors as $index => $subcontractorId) {

            // Example: rotate sites per subcontractor
            $assignedSites = [
                $sites['SIT-001'],
                $sites['SIT-002'],
                $sites['SIT-003'],
            ];

            foreach ($assignedSites as $siteId) {
                $rows[] = [
                    'site_id' => $siteId, // ✅ UUID
                    'subcontractor_id' => $subcontractorId, // ✅ UUID
                    'contract_type' => rand(0, 1)
                        ? 'QUASI_DELEGATION'
                        : 'FIXED_PRICE',
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        DB::table('site_subcontractors')->insert($rows);
    }
}
