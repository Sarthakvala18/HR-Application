<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kept in its own table so Finance can be granted access to payout data
     * without gaining access to the rest of the HR record, and so every read
     * can be logged at the table level.
     */
    public function up(): void
    {
        Schema::create('employee_payment_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();

            $table->string('payout_currency', 3)->nullable();
            $table->string('payout_currency_raw')->nullable();  // e.g. "EURO", "300 USD"
            $table->string('bank_country')->nullable();
            $table->string('bank_country_raw')->nullable();     // often holds a bank name
            $table->string('bank_name')->nullable();

            // All encrypted -> TEXT columns.
            $table->text('account_number')->nullable();
            $table->text('iban')->nullable();
            $table->text('swift_code')->nullable();
            $table->text('routing_number')->nullable();
            $table->text('address_line1')->nullable();
            $table->text('address_line2')->nullable();
            $table->text('city')->nullable();
            $table->text('state')->nullable();
            $table->text('postcode')->nullable();
            $table->string('address_country')->nullable();

            // Plaintext, non-identifying: lets the UI mask without decrypting.
            $table->string('account_last4', 8)->nullable();

            $table->string('source')->default('manual');
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->unique('employee_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_payment_details');
    }
};
