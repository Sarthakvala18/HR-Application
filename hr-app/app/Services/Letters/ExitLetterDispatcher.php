<?php

namespace App\Services\Letters;

use App\Mail\ExitLetterMail;
use App\Models\Employee;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use RuntimeException;

/**
 * Decides which exit letters a leaver gets, what is still needed to write them,
 * and delivers them.
 *
 * Facade over ExitLetterRenderer. Deliberately knows nothing about Zoho: the
 * letters are composed from the company letterhead, so no template registration
 * or field mapping is involved.
 */
class ExitLetterDispatcher
{
    /** Both letters are issued on exit. */
    public const TYPES = [LetterContent::RELIEVING, LetterContent::EXPERIENCE];

    /** Human labels for the gaps a sender is asked to fill. */
    public const FIELD_LABELS = [
        'name' => 'Full name',
        'employee_id' => 'Employee ID',
        'address' => 'Employee address',
        'position' => 'Job title',
        'team' => 'Team',
        'report_to' => 'Reported to',
        'join_date' => 'Joining date',
        'last_date' => 'Last working day',
        'hr_name' => 'HR signatory',
    ];

    public const DATE_KEYS = ['join_date', 'last_date'];

    /**
     * Gaps that map onto a real employee column, so answering once fixes the
     * record rather than only this one send.
     */
    public const COLUMNS = [
        'name' => 'full_name',
        'employee_id' => 'employee_code',
        'position' => 'position',
        'join_date' => 'date_of_joining',
        'last_date' => 'date_of_exit',
    ];

    public function __construct(private readonly ExitLetterRenderer $renderer) {}

    /** Human titles for the letters this person will receive. */
    public function titlesFor(Employee $employee): array
    {
        $content = app(LetterContent::class);

        return array_map(fn (string $type) => $content->title($type), self::TYPES);
    }

    public function recipientFor(Employee $employee): ?string
    {
        return $employee->personal_email ?: $employee->work_email ?: null;
    }

    /**
     * Everything still missing across both letters, de-duplicated and ordered
     * the way the letters read.
     *
     * @param  array<string, string>  $context
     * @return list<string>
     */
    public function missingFor(Employee $employee, array $context = []): array
    {
        $missing = [];

        foreach (self::TYPES as $type) {
            $missing = array_merge($missing, $this->renderer->missingFor($employee, $type, $context));
        }

        $ordered = array_keys(self::FIELD_LABELS);

        $missing = array_values(array_unique($missing));

        usort($missing, fn (string $a, string $b) => array_search($a, $ordered, true) <=> array_search($b, $ordered, true));

        return $missing;
    }

    /**
     * Renders one letter, for preview.
     *
     * @param  array<string, string>  $context
     * @return array{name: string, pdf: string}
     */
    public function renderOne(Employee $employee, string $type, array $context = []): array
    {
        return [
            'name' => $this->renderer->filename($employee, $type),
            'pdf' => $this->renderer->render($employee, $type, $context),
        ];
    }

    /**
     * Renders both letters and emails them as attachments.
     *
     * Returns one row per letter so a caller can tell a partial failure from a
     * total one. Nothing is sent unless every letter rendered: half a set of
     * exit documents is worse than none, because the gap is invisible to the
     * person receiving them.
     *
     * @param  array<string, string>  $context
     * @return list<array{type: string, letter: string, ok: bool, error: string|null}>
     */
    public function email(Employee $employee, array $context = []): array
    {
        $recipient = $this->recipientFor($employee);

        if ($recipient === null) {
            throw new RuntimeException('No email address on record for '.$employee->full_name.'.');
        }

        $content = app(LetterContent::class);

        $rendered = [];
        $results = [];

        foreach (self::TYPES as $type) {
            $title = $content->title($type);

            try {
                $letter = $this->renderOne($employee, $type, $context);

                $rendered[] = [
                    'type' => ucfirst($type),
                    'title' => $title,
                    'name' => $letter['name'],
                    'pdf' => $letter['pdf'],
                ];

                $results[] = ['type' => $type, 'letter' => $title, 'ok' => true, 'error' => null];
            } catch (RuntimeException|InvalidArgumentException $e) {
                $results[] = ['type' => $type, 'letter' => $title, 'ok' => false, 'error' => $e->getMessage()];
            }
        }

        $failed = array_filter($results, fn (array $r) => ! $r['ok']);

        if ($failed !== []) {
            return $results;
        }

        Mail::to($recipient)->send(new ExitLetterMail($employee, $rendered));

        return $results;
    }
}
