<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();          // used in Typeform hidden fields
            $table->string('employee_code')->nullable()->unique();

            $table->string('full_name');
            $table->string('preferred_name')->nullable();

            // Null until the Google account is created manually.
            $table->string('work_email')->nullable()->unique();
            $table->string('personal_email')->nullable()->index();
            $table->string('phone')->nullable();

            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('manager_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('role_template_id')->nullable()->constrained()->nullOnDelete();
            $table->string('position')->nullable();

            $table->string('employment_type')->default('full_time');
            $table->string('status')->default('pre_onboarding');

            $table->date('date_of_joining')->nullable();
            $table->date('date_of_exit')->nullable();
            $table->date('birthday')->nullable();
            $table->boolean('birth_year_known')->default(true);

            $table->string('country')->nullable();
            $table->string('timezone')->nullable();

            // Encrypted: ciphertext needs TEXT, never a sized string.
            $table->text('salary_amount')->nullable();
            $table->string('salary_currency', 3)->nullable();
            $table->string('salary_period')->default('monthly');

            $table->boolean('is_entity')->default(false); // company/trading-name payee
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'department_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
