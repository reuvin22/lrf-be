<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SubcontractorWorkerSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('subcontractor_workers')->insert([
            [
                'worker_id' => (string) Str::uuid(),
                'subcontractor_id' => "91ea73bb-f29e-4530-9782-e22e454358c1",
                'name' => 'Takashi Tanaka',
                'name_kana' => 'タカシ タナカ',
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'worker_id' => (string) Str::uuid(),
                'subcontractor_id' => "91ea73bb-f29e-4530-9782-e22e454358c1",
                'name' => 'Kenta Suzuki',
                'name_kana' => 'ケンタ スズキ',
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'worker_id' => (string) Str::uuid(),
                'subcontractor_id' => '5903951a-44e1-43a9-9ade-7d490d8de847',
                'name' => 'Yuta Sato',
                'name_kana' => 'ユウタ サトウ',
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'worker_id' => (string) Str::uuid(),
                'subcontractor_id' => '5903951a-44e1-43a9-9ade-7d490d8de847',
                'name' => 'Daichi Nakamura',
                'name_kana' => 'ダイチ ナカムラ',
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'worker_id' => (string) Str::uuid(),
                'subcontractor_id' => "4ae72acd-0575-4fa8-95c6-06875d939f66",
                'name' => 'Shota Yamamoto',
                'name_kana' => 'ショウタ ヤマモト',
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'worker_id' => (string) Str::uuid(),
                'subcontractor_id' => "4ae72acd-0575-4fa8-95c6-06875d939f66",
                'name' => 'Ren Kobayashi',
                'name_kana' => 'レン コバヤシ',
                'status' => 'INACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'worker_id' => (string) Str::uuid(),
                'subcontractor_id' => "38effcdd-39e4-4dfb-bd38-6cdffe199996",
                'name' => 'Haruto Ito',
                'name_kana' => 'ハルト イトウ',
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'worker_id' => (string) Str::uuid(),
                'subcontractor_id' => "fea0ca1a-e904-4b1a-8895-aa5b2a47c098",
                'name' => 'Sora Fujimoto',
                'name_kana' => 'ソラ フジモト',
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}
