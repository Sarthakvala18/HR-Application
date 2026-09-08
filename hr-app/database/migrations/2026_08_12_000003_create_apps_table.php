<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('apps', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');

            // automated = API does it; manual = guided task card for HR;
            // semi = attempt API, fall back to a task card.
            $table->string('provisioning_mode')->default('manual');

            $table->string('console_url')->nullable();
            $table->boolean('supports_license_tiers')->default(false);
            $table->json('license_tiers')->nullable();

            // Step-by-step text rendered on the manual task card.
            $table->text('onboard_instructions_md')->nullable();
            $table->text('offboard_instructions_md')->nullable();

            // Higher runs first during offboarding: access-killing steps lead.
            $table->unsignedSmallInteger('offboard_priority')->default(100);

            $table->boolean('costs_money')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('apps');
    }
};
