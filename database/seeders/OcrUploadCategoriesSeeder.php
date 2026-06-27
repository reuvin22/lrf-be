<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OcrUploadCategoriesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $categories = [
            [
                'category_id' => (string) Str::uuid(),
                'category_name' => 'Ad-hoc Transport',
                'description' => 'Expenses related to ad-hoc transport',
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'category_id' => (string) Str::uuid(),
                'category_name' => 'Transportation',
                'description' => 'Regular transportation expenses',
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'category_id' => (string) Str::uuid(),
                'category_name' => 'Meals',
                'description' => 'Meal related expenses',
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'category_id' => (string) Str::uuid(),
                'category_name' => 'Supplies',
                'description' => 'Office or project supplies',
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ];

        DB::table('ocr_upload_categories')->insert($categories);
    }
}
