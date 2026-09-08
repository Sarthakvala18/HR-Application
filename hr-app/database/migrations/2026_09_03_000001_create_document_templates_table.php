<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Maps a document type plus department to a Zoho Sign template.
     *
     * field_map exists because the same logical letter uses different field
     * labels in different templates ("Last Date" in one, "End Date" in
     * another), so the mapping has to be data, not code.
     */
    public function up(): void
    {
        Schema::create('document_templates', function (Blueprint $table) {
            $table->id();
            $table->string('type');                     // relieving|experience|contract|appointment|nda
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();

            $table->string('name');
            $table->string('zoho_template_id')->nullable();

            // logical key => exact Zoho field label
            $table->json('field_map')->nullable();
            // logical keys the template expects as dates
            $table->json('date_fields')->nullable();

            $table->boolean('is_default')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamp('verified_at')->nullable();   // fields confirmed against the live API
            $table->timestamps();

            $table->index(['type', 'department_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_templates');
    }
};
