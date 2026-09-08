<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_template_apps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_template_id')->constrained()->cascadeOnDelete();
            $table->foreignId('app_id')->constrained()->cascadeOnDelete();
            $table->string('license_tier')->nullable();
            $table->json('scopes')->nullable();   // groups, collections, departments
            $table->boolean('required')->default(true);
            $table->timestamps();

            $table->unique(['role_template_id', 'app_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_template_apps');
    }
};
