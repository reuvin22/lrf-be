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
        Schema::create('subcontractor_workers', function (Blueprint $table) {
            $table->uuid('worker_id')->primary();
            $table->uuid('subcontractor_id')->nullable()->index();
            $table->string('name')->index();
            $table->string('name_kana')->nullable();

            $table->enum('status', [
                'ACTIVE',
                'INACTIVE'
            ])->index();

            $table->timestamps();

            $table->index(['subcontractor_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sub_contractors_workers');
    }
};
