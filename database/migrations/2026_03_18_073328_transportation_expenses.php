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
        Schema::create('transportation_expenses', function (Blueprint $table) {
            $table->uuid('expense_id')->primary();
            $table->uuid('attendance_id');
            $table->uuid('employee_id');
            $table->string('site_id');
            $table->integer('amount');
            $table->string('route')->nullable();
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
        Schema::dropIfExists('transportation_expenses');
    }
};
