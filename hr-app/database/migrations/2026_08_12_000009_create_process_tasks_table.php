<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('process_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('process_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('app_id')->nullable()->constrained()->nullOnDelete();

            $table->string('key');
            $table->string('title');
            $table->text('description_md')->nullable();

            $table->string('mode')->default('manual');    // automatic|manual|approval
            $table->string('status')->default('pending');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->json('depends_on')->nullable();       // array of task keys

            $table->foreignId('assignee_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->json('payload')->nullable();
            $table->json('result')->nullable();
            $table->text('error')->nullable();

            // Manual steps must record proof: created account id, email, etc.
            $table->text('evidence')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->unique(['process_run_id', 'key']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('process_tasks');
    }
};
