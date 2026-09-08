<?php

namespace App\Enums;

enum EmploymentType: string
{
    case FullTime = 'full_time';
    case PartTime = 'part_time';
    case Commission = 'commission';
    case Freelancer = 'freelancer';
    case Contractor = 'contractor';

    public function label(): string
    {
        return match ($this) {
            self::FullTime => 'Full-Time',
            self::PartTime => 'Part-Time',
            self::Commission => 'Commission Based',
            self::Freelancer => 'Freelancer',
            self::Contractor => 'Contractor',
        };
    }

    /**
     * Freelancers and contractors sign an NDA/contract before any access is
     * granted; employees get the appointment letter instead.
     */
    public function requiresNdaBeforeAccess(): bool
    {
        return in_array($this, [self::Freelancer, self::Contractor], true);
    }

    public function contractDocumentType(): string
    {
        return $this->requiresNdaBeforeAccess() ? 'contract' : 'appointment';
    }

    /** Maps the free-text values found in the Typeform export. */
    public static function fromRaw(?string $raw): self
    {
        $n = strtolower(trim((string) $raw));
        $n = str_replace([' ', '-', '_'], '', $n);

        return match (true) {
            str_contains($n, 'fulltime') => self::FullTime,
            str_contains($n, 'parttime') => self::PartTime,
            str_contains($n, 'commission') => self::Commission,
            str_contains($n, 'freelance') => self::Freelancer,
            str_contains($n, 'contract') => self::Contractor,
            default => self::FullTime,
        };
    }

    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $t) => [$t->value => $t->label()])
            ->all();
    }
}
