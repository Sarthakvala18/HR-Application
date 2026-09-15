<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Supports rendering letters locally.
     *
     * The Zoho Sign licence permits creating documents but not sending them,
     * so the app fills the letter itself and emails it. That needs the blank
     * PDF and the field coordinates Zoho records for each template.
     */
    public function up(): void
    {
        Schema::table('document_templates', function (Blueprint $table) {
            $table->string('pdf_path')->nullable()->after('field_types');
            $table->json('field_positions')->nullable()->after('pdf_path');
        });
    }

    public function down(): void
    {
        Schema::table('document_templates', function (Blueprint $table) {
            $table->dropColumn(['pdf_path', 'field_positions']);
        });
    }
};
