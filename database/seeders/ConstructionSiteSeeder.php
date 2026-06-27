<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ConstructionSiteSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('construction_sites')->insert([
            [
                'site_code' => 'SITE-001',
                'site_name' => 'Shibuya Office Tower',
                'client_name' => 'Tokyo Urban Development',
                'contract_type' => 'QUASI_DELEGATION',
                'address' => 'Shibuya, Tokyo',
                'status' => 'IN_PROGRESS',
                'start_date' => '2026-01-10',
                'end_date' => '2026-08-30',
                'contract_amount' => 150000000,
                'dotto_genka_code' => 'DGK-1001',
            ],
            [
                'site_code' => 'SITE-002',
                'site_name' => 'Osaka Warehouse Expansion',
                'client_name' => 'Kansai Logistics',
                'contract_type' => 'FIXED_PRICE',
                'address' => 'Osaka, Japan',
                'status' => 'PREPARING',
                'start_date' => '2026-04-01',
                'end_date' => '2026-12-15',
                'contract_amount' => 98000000,
                'dotto_genka_code' => 'DGK-1002',
            ],
            [
                'site_code' => 'SITE-003',
                'site_name' => 'Nagoya Residential Block A',
                'client_name' => 'Nagoya Housing Corp',
                'contract_type' => 'QUASI_DELEGATION',
                'address' => 'Nagoya, Aichi',
                'status' => 'IN_PROGRESS',
                'start_date' => '2026-02-20',
                'end_date' => '2026-11-30',
                'contract_amount' => 125000000,
                'dotto_genka_code' => 'DGK-1003',
            ],
            [
                'site_code' => 'SITE-004',
                'site_name' => 'Yokohama Port Facility',
                'client_name' => 'Harbor Engineering Ltd',
                'contract_type' => 'FIXED_PRICE',
                'address' => 'Yokohama, Kanagawa',
                'status' => 'COMPLETED',
                'start_date' => '2025-03-15',
                'end_date' => '2026-01-30',
                'contract_amount' => 210000000,
                'dotto_genka_code' => 'DGK-1004',
            ],
            [
                'site_code' => 'SITE-005',
                'site_name' => 'Sapporo Commercial Center',
                'client_name' => 'Hokkaido Builders',
                'contract_type' => 'QUASI_DELEGATION',
                'address' => 'Sapporo, Hokkaido',
                'status' => 'PREPARING',
                'start_date' => '2026-05-10',
                'end_date' => '2027-02-28',
                'contract_amount' => 175000000,
                'dotto_genka_code' => 'DGK-1005',
            ],
        ]);
    }
}
