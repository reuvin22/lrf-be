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
        Schema::create('rates', function (Blueprint $table) {
            $table->uuid('rate_id')->primary();
            $table->enum('rate_type', [
                'EMPLOYEE_COST',
                'SUBCONTRACTOR_CONTRACT',
                'SUBCONTRACTOR_WORKER_CONTRACT'
            ]);

            $table->enum('target_type', [
                'EMPLOYEE',
                'SUBCONTRACTOR',
                'SUBCONTRACTOR_WORKER'
            ]);
            $table->uuid('target_id');
            $table->uuid('site_id')->nullable();
            $table->integer('unit_price');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestamps();

            $table->index(['target_type', 'target_id']);
            $table->index('site_id');
            $table->index(['effective_from', 'effective_to']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rates');
    }
};
