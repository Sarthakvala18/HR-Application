<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');

            // Offboarding data-migration destination for this department.
            // Encodes the matrix from the HR SOP: general/sales/finance -> admin,
            // product -> product, tech -> services, marketing -> manager or admin.
            $table->string('offboard_destination_email')->nullable();
            $table->boolean('offboard_to_manager_first')->default(false);

            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('departments');
    }
};
