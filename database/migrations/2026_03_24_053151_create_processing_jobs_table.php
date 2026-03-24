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
        Schema::create('processing_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('telegram_user_id')->constrained('telegram_users')->cascadeOnDelete();
            $table->bigInteger('chat_id')->index();
            $table->string('telegram_file_id')->nullable();
            $table->string('original_filename');
            $table->string('input_file_type')->nullable();
            $table->string('input_file_path')->nullable();
            $table->string('status', 32)->index();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable()->index();
            $table->timestamp('completed_at')->nullable()->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('processing_jobs');
    }
};
