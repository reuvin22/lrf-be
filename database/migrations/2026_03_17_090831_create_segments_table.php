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
       Schema::create('segments', function (Blueprint $table) {
            $table->uuid('segment_id')->primary();
            $table->uuid('attendance_id');
            $table->uuid('employee_id');
            $table->string('type');
            $table->enum('segment_type', ['TRAVEL', 'SITE', 'OFFICE']);
            $table->string('site_id')->nullable();
            $table->string('site_name')->nullable();
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
        Schema::dropIfExists('segments');
    }
};
