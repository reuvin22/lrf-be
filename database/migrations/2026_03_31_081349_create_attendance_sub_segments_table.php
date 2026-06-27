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
        Schema::create('attendance_subsegments', function (Blueprint $table) {
            $table->uuid()->primary();
            $table->uuid('attendance_id');
            $table->uuid('employee_id');
            $table->uuid('segment_id');
            $table->uuid('worker_id');
            $table->string('worker_name');
            $table->uuid('site_id');
            $table->string('site_name');
            $table->timestampTz('start_time');
            $table->timestampTz('end_time')->nullable();
            $table->string('contract_type');
            $table->uuid('company_id');
            $table->string('company_name');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance_subcontractor_segments');
    }
};
