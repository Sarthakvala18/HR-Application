<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

class AuditLogger
{
    public const PAYMENT_VIEWED = 'payment_details.viewed';

    public const PAYMENT_REVEALED = 'payment_details.revealed';

    public const PAYMENT_EXPORTED = 'payment_details.exported';

    public const ACCESS_GRANTED = 'app_access.granted';

    public const ACCESS_REVOKED = 'app_access.revoked';

    public const OFFBOARDING_TRIGGERED = 'offboarding.triggered';

    public const IMPORT_MATCH_ACCEPTED = 'import.match_accepted';

    public function log(
        string $action,
        ?Model $subject = null,
        array $changes = [],
        ?string $reason = null,
        ?User $user = null,
    ): AuditLog {
        return AuditLog::create([
            'user_id' => $user?->id ?? Auth::id(),
            'action' => $action,
            'auditable_type' => $subject ? $subject::class : null,
            'auditable_id' => $subject?->getKey(),
            'changes' => $changes ?: null,
            'reason' => $reason,
            'ip' => Request::ip(),
            'user_agent' => substr((string) Request::userAgent(), 0, 500),
            'created_at' => now(),
        ]);
    }

    /**
     * Reveals demand a reason. An audit trail that records "someone looked"
     * without recording "why" is not much of a trail.
     */
    public function logPaymentReveal(Model $paymentDetail, string $reason, ?User $user = null): AuditLog
    {
        return $this->log(
            action: self::PAYMENT_REVEALED,
            subject: $paymentDetail,
            reason: $reason,
            user: $user,
        );
    }
}
