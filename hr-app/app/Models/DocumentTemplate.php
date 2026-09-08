<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Zoho Sign template bound to a document type and (optionally) a department.
 *
 * `field_map` translates the app's logical keys into the exact field labels a
 * given template declares. It has to be data rather than code because the same
 * logical letter uses different labels in different templates.
 */
class DocumentTemplate extends Model
{
    use HasFactory;

    public const TYPE_RELIEVING = 'relieving';

    public const TYPE_EXPERIENCE = 'experience';

    public const TYPE_CONTRACT = 'contract';

    public const TYPE_APPOINTMENT = 'appointment';

    public const TYPE_NDA = 'nda';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'field_map' => 'array',
            'date_fields' => 'array',
            'signature_fields' => 'array',
            'field_types' => 'array',
            'is_default' => 'boolean',
            'active' => 'boolean',
            'verified_at' => 'datetime',
        ];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    /**
     * Best template for a type and department: an exact departmental match
     * wins, otherwise the type default.
     */
    public static function resolve(string $type, ?int $departmentId): ?self
    {
        return static::query()
            ->active()
            ->where('type', $type)
            ->where(fn (Builder $q) => $q
                ->where('department_id', $departmentId)
                ->orWhereNull('department_id'))
            // Department-specific rows sort before the generic fallback.
            ->orderByRaw('department_id IS NULL')
            ->first();
    }

    /** Whether the field labels have been confirmed against the live API. */
    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    public function isReady(): bool
    {
        return filled($this->zoho_template_id) && ! empty($this->field_map);
    }

    /**
     * Zoho field types the API supplies values for. Everything else — Name,
     * Date, Signature — is populated by Zoho during the signing ceremony.
     */
    public const TYPE_TEXT = 'Textfield';

    public const TYPE_DATE = 'CustomDate';

    /**
     * How a field should be filled, based on the type recorded from the API.
     *
     * Falls back to the hand-maintained lists when a template has not been
     * verified yet, so an unverified mapping still behaves sensibly.
     *
     * @return 'text'|'date'|'auto'
     */
    public function fillModeFor(string $logicalKey, string $zohoLabel): string
    {
        $types = $this->field_types ?? [];

        if (isset($types[$zohoLabel])) {
            return match ($types[$zohoLabel]) {
                self::TYPE_TEXT => 'text',
                self::TYPE_DATE => 'date',
                // Name, Date, Signature and anything else Zoho owns.
                default => 'auto',
            };
        }

        if (in_array($logicalKey, $this->signature_fields ?? [], true)) {
            return 'auto';
        }

        return in_array($logicalKey, $this->date_fields ?? [], true) ? 'date' : 'text';
    }

    /**
     * Fields the API cannot fill because Zoho collects them at signing time.
     *
     * @return array<int, string>
     */
    public function signatureLabels(): array
    {
        $signatureKeys = $this->signature_fields ?? [];

        return array_values(array_intersect_key(
            $this->field_map ?? [],
            array_flip($signatureKeys),
        ));
    }

    /** @return array<int, string> logical keys this template expects */
    public function logicalKeys(): array
    {
        return array_keys($this->field_map ?? []);
    }
}
