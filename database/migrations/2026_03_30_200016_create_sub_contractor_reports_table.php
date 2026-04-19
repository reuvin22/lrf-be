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
        Schema::create('sub_contractor_reports', function (Blueprint $table) {
            $table->uuid()->primary();
            $table->uuid('attendance_id');
            $table->uuid('employee_id');
            $table->uuid('worker_id')->nullable();
            $table->string('worker_name');
            $table->string('contract_type');
            $table->string('company_name');
            $table->uuid('site_id');
            $table->timestampTz('start_time');
            $table->timestampTz('end_time')->nullable();
            $table->timestamps();
            $table->foreign('attendance_id')
                ->references('attendance_id')
                ->on('attendances')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sub_contractor_reports');
    }
};
