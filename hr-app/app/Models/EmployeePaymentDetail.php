<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bank and payout data. Every string that could identify an account is
 * encrypted at rest; `account_last4` is stored in the clear purely so the UI
 * can mask without decrypting anything.
 */
class EmployeePaymentDetail extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    /** Never serialise the secrets, even by accident. */
    protected $hidden = [
        'account_number',
        'iban',
        'swift_code',
        'routing_number',
        'address_line1',
        'address_line2',
        'city',
        'state',
        'postcode',
    ];

    protected function casts(): array
    {
        return [
            'account_number' => 'encrypted',
            'iban' => 'encrypted',
            'swift_code' => 'encrypted',
            'routing_number' => 'encrypted',
            'address_line1' => 'encrypted',
            'address_line2' => 'encrypted',
            'city' => 'encrypted',
            'state' => 'encrypted',
            'postcode' => 'encrypted',
            'verified_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Keep the maskable tail in sync whenever the account number changes.
        static::saving(function (EmployeePaymentDetail $detail) {
            $account = $detail->account_number;

            $detail->account_last4 = is_string($account) && $account !== ''
                ? substr(preg_replace('/\s+/', '', $account), -4)
                : null;
        });
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /** What the UI shows before anyone clicks "reveal". */
    public function maskedAccountNumber(): string
    {
        return $this->account_last4 ? '•••• '.$this->account_last4 : '—';
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }
}
