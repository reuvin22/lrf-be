<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('site_subcontractors', function (Blueprint $table) {
            $table->uuid()->primary();

            $table->uuid('site_id');
            $table->uuid('subcontractor_id');

            $table->enum('contract_type', [
                'QUASI_DELEGATION',
                'FIXED_PRICE'
            ])->index();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('site_subcontractors');
    }
};
