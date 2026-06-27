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
        Schema::create('attendances', function (Blueprint $table) {
            $table->uuid('attendance_id')->primary();
            $table->uuid('employee_id');
            $table->date('work_date');
            $table->enum('status', ['WORKING', 'END_OF_DAY', 'NOT_STARTED']);
            $table->integer('total_work_minutes')->default(0);
            $table->integer('overtime_minutes')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendances');
    }
};
