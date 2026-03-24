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
        Schema::create('generated_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('processing_job_id')->constrained('processing_jobs')->cascadeOnDelete();
            $table->string('variant');
            $table->string('file_name');
            $table->string('file_path');
            $table->string('mime_type');
            $table->timestamps();

            $table->index('variant');
            $table->unique(['processing_job_id', 'variant']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('generated_files');
    }
};
