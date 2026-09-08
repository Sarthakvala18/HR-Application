<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable record of a Typeform response. The raw payload is encrypted because
 * bank submissions carry account numbers and home addresses.
 */
class FormSubmission extends Model
{
    use HasFactory;

    public const FORM_PAPERWORK = 'paperwork';

    public const FORM_BANK = 'bank';

    public const FORM_LEAVE = 'leave';

    /** Below this, a name match is never applied without human confirmation. */
    public const AUTO_ACCEPT_CONFIDENCE = 95;

    protected $guarded = ['id'];

    protected $hidden = ['raw_payload'];

    protected function casts(): array
    {
        return [
            'raw_payload' => 'encrypted:array',
            'normalized' => 'array',
            'issues' => 'array',
            'submitted_at' => 'datetime',
            'processed_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function scopeNeedsReview(Builder $query): Builder
    {
        return $query->where('review_status', 'pending');
    }

    public function scopeQuarantined(Builder $query): Builder
    {
        return $query->where('review_status', 'quarantined');
    }

    /**
     * Bank rows are never auto-linked on a name, not even an exact one.
     *
     * The bank form collects no email address, so a name is the only available
     * key, and two people can share a name exactly. Paying the wrong person is
     * the failure mode this guard exists for: only a deterministic key
     * (the hidden employee_id field, or an email) may link a payout record.
     */
    public function canAutoApply(): bool
    {
        $isDeterministic = in_array($this->match_method, ['hidden_field', 'email'], true);

        if ($this->form_key === self::FORM_BANK) {
            return $isDeterministic;
        }

        return $isDeterministic
            || ($this->match_confidence ?? 0) >= self::AUTO_ACCEPT_CONFIDENCE;
    }

    public function hasIssues(): bool
    {
        return ! empty($this->issues);
    }
}
