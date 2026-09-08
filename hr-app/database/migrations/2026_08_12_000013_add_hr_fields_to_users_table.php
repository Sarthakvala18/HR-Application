<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('employee')->after('email');
            $table->string('google_id')->nullable()->unique()->after('role');
            $table->foreignId('employee_id')->nullable()->after('google_id')
                ->constrained()->nullOnDelete();
            $table->boolean('is_active')->default(true)->after('employee_id');
            $table->timestamp('last_login_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('employee_id');
            $table->dropColumn(['role', 'google_id', 'is_active', 'last_login_at']);
        });
    }
};
