<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Signature fields are completed during the Zoho signing ceremony, not by
     * the API, so they must be tracked separately from fillable fields.
     * Otherwise they look like required values the app failed to supply.
     */
    public function up(): void
    {
        Schema::table('document_templates', function (Blueprint $table) {
            $table->json('signature_fields')->nullable()->after('date_fields');
            $table->text('notes')->nullable()->after('signature_fields');
        });
    }

    public function down(): void
    {
        Schema::table('document_templates', function (Blueprint $table) {
            $table->dropColumn(['signature_fields', 'notes']);
        });
    }
};
