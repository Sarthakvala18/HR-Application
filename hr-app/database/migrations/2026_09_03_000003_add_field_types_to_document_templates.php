<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Zoho field types, learned from the API during verification.
     *
     * The type decides how a field is filled: Textfield and CustomDate are
     * supplied by us, while Name, Date and Signature are populated by Zoho
     * during the signing ceremony. Guessing this from a label is not possible,
     * so it is recorded from the template itself.
     */
    public function up(): void
    {
        Schema::table('document_templates', function (Blueprint $table) {
            $table->json('field_types')->nullable()->after('signature_fields');
        });
    }

    public function down(): void
    {
        Schema::table('document_templates', function (Blueprint $table) {
            $table->dropColumn('field_types');
        });
    }
};
