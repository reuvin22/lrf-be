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
        Schema::create('construction_sites', function (Blueprint $table) {
            $table->uuid('site_id')->primary();
            $table->string('site_code')->nullable();
            $table->string('site_name');
            $table->string('client_name')->nullable();
            $table->enum('contract_type', [
                'QUASI_DELEGATION',
                'FIXED_PRICE'
            ]);
            $table->string('address')->nullable();
            $table->enum('status', [
                'PREPARING',
                'IN_PROGRESS',
                'COMPLETED'
            ]);
            $table->date('start_date')->nullable()->index();
            $table->date('end_date')->nullable()->index();
            $table->integer('contract_amount')->nullable();
            $table->string('dotto_genka_code')->nullable()->index();
            $table->timestamps();
            $table->index(['status', 'contract_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('construction_sites');
    }
};
