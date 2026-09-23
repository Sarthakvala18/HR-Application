<?php

namespace App\Services\Letters;

use App\Models\Employee;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Turns an employee record into a finished exit letter PDF.
 *
 * Facade over LetterContent (what the letter says) and LetterDocumentBuilder
 * (how it is laid out), so callers need to know neither.
 *
 * The home address is deliberately NOT read from the encrypted payment record.
 * Revealing that is policy-gated and audited; a letter generator quietly
 * decrypting it would route around both. It has to be passed in explicitly, and
 * is omitted from the letter when absent rather than printed as an empty line.
 */
class ExitLetterRenderer
{
    /** One date format everywhere, so a letter can never mix dd/mm with ISO. */
    public const DATE_FORMAT = 'j F Y';

    public function __construct(private readonly LetterContent $content) {}

    /**
     * @param  array<string, string>  $context  Values with no column of their own:
     *                                          address, report_to, hr_name, last_date.
     */
    public function render(Employee $employee, string $type, array $context = []): string
    {
        $values = $this->values($employee, $context);

        $missing = $this->missing($type, $values);

        if ($missing !== []) {
            throw new RuntimeException(
                'Cannot write the letter: missing '.implode(', ', $missing)
                .' for '.($employee->full_name ?: 'this employee').'.'
            );
        }

        $path = storage_path('app/'.ltrim((string) config('letters.letterhead'), '/'));

        if (! is_file($path)) {
            throw new RuntimeException('Letterhead not found at '.$path);
        }

        $builder = new LetterDocumentBuilder($path);

        return $builder->compose($this->content->blocks($type, $values));
    }

    public function filename(Employee $employee, string $type): string
    {
        $slug = preg_replace('/[^A-Za-z0-9]+/', '-', $employee->full_name ?: 'employee');
        $label = $type === LetterContent::RELIEVING ? 'Relieving-Letter' : 'Experience-Letter';

        return trim($label.'-'.$slug, '-').'.pdf';
    }

    /**
     * Values the letter needs but cannot supply itself. Returned so a caller
     * can ask for them before attempting to render.
     *
     * @param  array<string, string>  $context
     * @return list<string>
     */
    public function missingFor(Employee $employee, string $type, array $context = []): array
    {
        return $this->missing($type, $this->values($employee, $context));
    }

    /**
     * @param  array<string, string>  $values
     * @return list<string>
     */
    private function missing(string $type, array $values): array
    {
        // Address is optional: the row is dropped when it is not supplied.
        $required = ['name', 'position', 'team', 'join_date', 'last_date', 'hr_name'];

        if ($type === LetterContent::RELIEVING) {
            $required[] = 'report_to';
            $required[] = 'employee_id';
        }

        return array_values(array_filter(
            $required,
            fn (string $key) => blank($values[$key] ?? null),
        ));
    }

    /**
     * @param  array<string, string>  $context
     * @return array<string, string>
     */
    private function values(Employee $employee, array $context): array
    {
        $exit = $context['last_date']
            ?? $employee->date_of_exit?->format(self::DATE_FORMAT);

        return [
            'name' => $employee->full_name ?? '',
            'employee_id' => $employee->employee_code ?? '',
            'address' => $context['address'] ?? '',
            'position' => $employee->position ?? '',
            'team' => $employee->department?->name ?? '',
            'report_to' => $context['report_to'] ?? $employee->manager?->full_name ?? '',
            'join_date' => $this->date($employee->date_of_joining),
            'last_date' => $this->date($exit),
            'letter_date' => now()->format(self::DATE_FORMAT),
            'hr_name' => $context['hr_name']
                ?? config('zoho.sign.hr_name')
                ?? config('letters.hr_name'),
            'hr_email' => config('letters.hr_email'),
        ];
    }

    /** Normalises whatever shape a date arrives in to the single letter format. */
    private function date(mixed $value): string
    {
        if (blank($value)) {
            return '';
        }

        if ($value instanceof Carbon) {
            return $value->format(self::DATE_FORMAT);
        }

        try {
            return Carbon::parse((string) $value)->format(self::DATE_FORMAT);
        } catch (\Throwable) {
            // Already a human-written date such as "15 July 2024".
            return (string) $value;
        }
    }
}
