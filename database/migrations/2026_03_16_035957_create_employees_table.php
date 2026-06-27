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
        Schema::create('employees', function (Blueprint $table) {
            $table->uuid('employee_id')->primary();
            $table->string('employee_code')->nullable();
            $table->string('name');
            $table->string('name_kana')->nullable();
            $table->string('line_user_id')->nullable();
            $table->string('email')->nullable();
            $table->enum('employment_type', ['FULL_TIME', 'PART_TIME', 'CONTRACT']);
            $table->enum('role', ['GENERAL', 'ADMIN', 'ACCOUNTING']);
            $table->integer('base_salary');
            $table->decimal('monthly_work_hours', 8, 2);
            $table->integer('cost_rate');
            $table->integer('commute_cost_monthly')->nullable();
            $table->date('joined_date')->nullable();
            $table->enum('status', ['ACTIVE', 'RESIGNED']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
