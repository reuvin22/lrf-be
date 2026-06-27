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
        Schema::create('subcontractors', function (Blueprint $table) {
            $table->uuid('subcontractor_id')->primary();
            $table->string('company_name')->index();
            $table->string('contact_person')->nullable()->index();
            $table->string('contact_phone')->nullable()->index();
            $table->enum('status', [
                'ACTIVE',
                'TERMINATED'
            ]);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sub_contractors');
    }
};
