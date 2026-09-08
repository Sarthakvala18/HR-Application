<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();

            $table->string('type');   // contract|nda|appointment|relieving|experience
            $table->string('status')->default('draft');

            $table->string('zoho_request_id')->nullable()->index();
            $table->string('zoho_template_id')->nullable();

            $table->longText('draft_body')->nullable();   // Claude-drafted, HR-edited
            $table->string('signed_pdf_path')->nullable();

            $table->timestamp('sent_at')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->timestamp('declined_at')->nullable();

            $table->timestamps();
            $table->index(['employee_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
