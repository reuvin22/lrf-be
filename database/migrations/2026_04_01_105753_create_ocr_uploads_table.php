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
        Schema::create('ocr_uploads', function (Blueprint $table) {
            $table->uuid('upload_id')->primary();
            $table->uuid('uploaded_by');
            $table->uuid('category_id');
            $table->uuid('site_id')->nullable();
            $table->uuid('subcontractor_id')->nullable();
            $table->uuid('confirmed_by')->nullable();
            $table->uuid('attendance_id');
            $table->enum('upload_source', ['LINE', 'Web']);
            $table->enum('status', ['PENDING', 'PROCESSING', 'COMPLETED', 'ERROR'])
                  ->default('PENDING');
            $table->string('image_path')->nullable();
            $table->integer('ocr_result_amount')->nullable();
            $table->date('ocr_result_date')->nullable();
            $table->text('ocr_result_raw')->nullable();
            $table->boolean('confirmed')->default(false);
            $table->timestamp('confirmed_at')->nullable();
            $table->string('note')->nullable();
            $table->timestamp('uploaded_at')->nullable();
            $table->timestamp('processed_at')->nullable();

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
        Schema::dropIfExists('ocr_uploads');
    }
};
