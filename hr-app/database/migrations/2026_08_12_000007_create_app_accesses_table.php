<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The access matrix: one row per employee x app.
     */
    public function up(): void
    {
        Schema::create('app_accesses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('app_id')->constrained()->cascadeOnDelete();

            $table->string('status')->default('none');
            $table->string('license_tier')->nullable();
            $table->string('external_id')->nullable();   // Zoom/Slack/Zoho/Google id
            $table->json('scopes')->nullable();

            $table->timestamp('granted_at')->nullable();
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->unique(['employee_id', 'app_id']);
            $table->index(['app_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_accesses');
    }
};
