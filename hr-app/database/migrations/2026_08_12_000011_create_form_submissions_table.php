<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Raw intake, never mutated. Every Typeform response lands here first so a
     * bad import can always be replayed from source.
     */
    public function up(): void
    {
        Schema::create('form_submissions', function (Blueprint $table) {
            $table->id();
            $table->string('form_key');                 // paperwork|bank|leave
            $table->string('response_id')->nullable();
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();

            // Encrypted: bank submissions carry account numbers and home addresses.
            $table->longText('raw_payload');

            $table->string('source')->default('csv_import');   // csv_import|webhook
            $table->string('match_method')->nullable();        // hidden_field|email|name_fuzzy|manual
            $table->unsignedTinyInteger('match_confidence')->nullable();  // 0-100

            $table->string('review_status')->default('pending'); // pending|accepted|discarded|quarantined
            $table->text('review_notes')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();

            $table->json('normalized')->nullable();   // parsed fields + per-field flags
            $table->json('issues')->nullable();       // detected data-quality problems

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['form_key', 'response_id']);
            $table->index(['form_key', 'review_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_submissions');
    }
};
