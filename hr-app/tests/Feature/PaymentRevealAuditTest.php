<?php

namespace Tests\Feature;

use App\Models\AppAccess;
use App\Models\AuditLog;
use App\Models\EmployeePaymentDetail;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentRevealAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_reveal_writes_an_audit_row_naming_the_user_and_the_reason(): void
    {
        // Arrange
        $finance = User::factory()->finance()->create();
        $detail = EmployeePaymentDetail::factory()->create();
        $this->actingAs($finance);

        // Act
        app(AuditLogger::class)->logPaymentReveal($detail, 'Verifying account before the payout run.');

        // Assert
        $log = AuditLog::sole();

        $this->assertSame(AuditLogger::PAYMENT_REVEALED, $log->action);
        $this->assertSame($finance->id, $log->user_id);
        $this->assertSame('Verifying account before the payout run.', $log->reason);
        $this->assertSame(EmployeePaymentDetail::class, $log->auditable_type);
        $this->assertSame($detail->id, $log->auditable_id);
        $this->assertTrue($log->isSensitiveAccess());
    }

    public function test_access_grants_and_revocations_are_logged(): void
    {
        $user = User::factory()->hrAdmin()->create();
        $this->actingAs($user);

        $access = AppAccess::factory()->create();

        app(AuditLogger::class)->log(AuditLogger::ACCESS_GRANTED, $access, ['app' => 'zoom']);
        app(AuditLogger::class)->log(AuditLogger::ACCESS_REVOKED, $access, ['app' => 'zoom']);

        $this->assertSame(2, AuditLog::count());
        $this->assertEqualsCanonicalizing(
            [AuditLogger::ACCESS_GRANTED, AuditLogger::ACCESS_REVOKED],
            AuditLog::pluck('action')->all(),
        );
        $this->assertSame(['app' => 'zoom'], AuditLog::first()->changes);
    }

    public function test_audit_rows_capture_request_context(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        app(AuditLogger::class)->log('test.action');

        $log = AuditLog::sole();

        $this->assertNotNull($log->created_at);
        $this->assertNotNull($log->ip);
    }

    public function test_the_audit_log_has_no_updated_at_so_rows_are_append_only(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        app(AuditLogger::class)->log('test.action');

        $this->assertNull(AuditLog::UPDATED_AT);
        $this->assertArrayNotHasKey('updated_at', AuditLog::sole()->getAttributes());
    }
}
