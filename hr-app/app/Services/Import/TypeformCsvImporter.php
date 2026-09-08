<?php

namespace App\Services\Import;

use App\Enums\EmployeeStatus;
use App\Enums\EmploymentType;
use App\Models\Employee;
use App\Models\EmployeePaymentDetail;
use App\Models\FormSubmission;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Loads the historical Typeform CSV exports into staging.
 *
 * These files are an archive of submissions, not a roster, so nothing here
 * marks anyone as currently employed. Rows land in form_submissions with a
 * proposed match; HR confirms them in the review queue.
 */
class TypeformCsvImporter
{
    public const FORM_PAPERWORK = FormSubmission::FORM_PAPERWORK;

    public const FORM_BANK = FormSubmission::FORM_BANK;

    private array $stats = [
        'read' => 0,
        'imported' => 0,
        'duplicates' => 0,
        'quarantined' => 0,
        'needs_review' => 0,
        'auto_linked' => 0,
        'issues' => 0,
    ];

    public function __construct(private readonly bool $dryRun = false) {}

    public function import(string $formKey, string $path): array
    {
        if (! is_readable($path)) {
            throw new RuntimeException("Cannot read CSV at {$path}");
        }

        $rows = $this->readCsv($path);
        $this->stats['read'] = count($rows);

        foreach ($rows as $row) {
            $formKey === self::FORM_PAPERWORK
                ? $this->handlePaperworkRow($row)
                : $this->handleBankRow($row);
        }

        return $this->stats;
    }

    /**
     * Reads the CSV with header mapping. Typeform exports quote embedded
     * newlines, so a row can legitimately span several physical lines.
     *
     * @return array<int, array<string, string>>
     */
    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw new RuntimeException("Cannot open {$path}");
        }

        $header = fgetcsv($handle, escape: '');

        if ($header === false) {
            fclose($handle);

            throw new RuntimeException('CSV has no header row');
        }

        // Strip a UTF-8 BOM from the first header cell if present.
        $header[0] = preg_replace('/^\x{FEFF}/u', '', (string) $header[0]);
        $header = array_map(fn ($h) => trim((string) $h), $header);

        $rows = [];

        while (($data = fgetcsv($handle, escape: '')) !== false) {
            if ($data === [null] || (count($data) === 1 && trim((string) $data[0]) === '')) {
                continue;
            }

            $data = array_pad(array_slice($data, 0, count($header)), count($header), null);
            $rows[] = array_combine($header, array_map(fn ($v) => $v === null ? null : trim($v), $data));
        }

        fclose($handle);

        return $rows;
    }

    /** Header lookup tolerant of the long question-style column names. */
    private function pick(array $row, array $needles): ?string
    {
        foreach ($row as $column => $value) {
            $haystack = strtolower($column);

            foreach ($needles as $needle) {
                if (str_contains($haystack, strtolower($needle))) {
                    return $value === null || $value === '' ? null : $value;
                }
            }
        }

        return null;
    }

    // ------------------------------------------------------------- paperwork

    private function handlePaperworkRow(array $row): void
    {
        $responseId = $row['#'] ?? null;
        $name = $this->pick($row, ['name of the onboarding member', 'name']);
        $email = $this->pick($row, ['personal email', 'email']);

        if ($this->alreadyImported(self::FORM_PAPERWORK, $responseId)) {
            $this->stats['duplicates']++;

            return;
        }

        $issues = [];

        if (NameMatcher::isLikelyTestRow($name, $email)) {
            $this->store(self::FORM_PAPERWORK, $responseId, $row, [
                'review_status' => 'quarantined',
                'review_notes' => 'Looks like a test submission.',
                'issues' => ['test_row'],
            ]);
            $this->stats['quarantined']++;

            return;
        }

        $salary = ValueNormalizer::salary($this->pick($row, ['salary']));
        $joining = ValueNormalizer::date($this->pick($row, ['date of joining']));
        $employmentType = EmploymentType::fromRaw($this->pick($row, ['employment type']));

        foreach ([$salary['issue'] ?? null, $joining['issue'] ?? null] as $issue) {
            if ($issue !== null) {
                $issues[] = $issue;
            }
        }

        if (NameMatcher::isLikelyEntity($name)) {
            $issues[] = 'payee_is_entity';
        }

        $normalized = [
            'full_name' => $name,
            'personal_email' => $email,
            'phone' => ltrim((string) $this->pick($row, ['contact number']), "'"),
            'position' => $this->pick($row, ['position']),
            'employment_type' => $employmentType->value,
            'salary_amount' => $salary['value'],
            'salary_currency' => $salary['currency'],
            'date_of_joining' => $joining['value'],
            'submitted_at' => $this->pick($row, ['submit date']),
        ];

        // The paperwork form carries an email, so it can create identities.
        $match = NameMatcher::findBest((string) $name, $email);

        $this->store(self::FORM_PAPERWORK, $responseId, $row, [
            'normalized' => $normalized,
            'issues' => $issues ?: null,
            'employee_id' => $match['employee']?->id,
            'match_method' => $match['method'],
            'match_confidence' => $match['confidence'],
            'review_status' => $issues === [] ? 'pending' : 'pending',
        ]);

        $this->stats['imported']++;
        $this->stats['needs_review']++;

        if ($issues !== []) {
            $this->stats['issues']++;
        }
    }

    // ------------------------------------------------------------------ bank

    private function handleBankRow(array $row): void
    {
        $responseId = $row['#'] ?? null;
        $name = $this->pick($row, ['name']);

        if ($this->alreadyImported(self::FORM_BANK, $responseId)) {
            $this->stats['duplicates']++;

            return;
        }

        if (NameMatcher::isLikelyTestRow($name)) {
            $this->store(self::FORM_BANK, $responseId, $row, [
                'review_status' => 'quarantined',
                'review_notes' => 'Looks like a test submission.',
                'issues' => ['test_row'],
            ]);
            $this->stats['quarantined']++;

            return;
        }

        $issues = [];

        $accountNumber = $this->pick($row, ['bank account number', 'account number']);
        $swiftColumn = $this->pick($row, ['swift']);
        $ibanColumn = $this->pick($row, ['iban']);

        $ibanParts = ValueNormalizer::ibanColumn($ibanColumn, $accountNumber, $swiftColumn);
        $currency = ValueNormalizer::currency($this->pick($row, ['currency']));
        $bankCountry = ValueNormalizer::bankCountry($this->pick($row, ['country of your bank']));
        $addressCountry = ValueNormalizer::country($this->pick($row, ['country']));

        foreach ([
            $ibanParts['issue'] ?? null,
            $currency['issue'] ?? null,
            $bankCountry['issue'] ?? null,
        ] as $issue) {
            if ($issue !== null) {
                $issues[] = $issue;
            }
        }

        if (NameMatcher::isLikelyEntity($name)) {
            $issues[] = 'payee_is_entity';
        }

        $normalized = [
            'full_name' => $name,
            'payout_currency' => $currency['value'],
            'payout_currency_raw' => $currency['raw'],
            'bank_country' => $bankCountry['country'],
            'bank_country_raw' => $bankCountry['raw'],
            'bank_name' => $bankCountry['bank_name'],
            'account_number' => $accountNumber,
            'iban' => $ibanParts['iban'],
            'swift_code' => $ibanParts['swift_code'] ?? $swiftColumn,
            'routing_number' => $ibanParts['routing_number'],
            'address_line1' => $this->pick($row, ['address']),
            'address_line2' => $this->pick($row, ['address line 2']),
            'city' => $this->pick($row, ['city']),
            'state' => $this->pick($row, ['state']),
            'postcode' => $this->pick($row, ['zip']),
            'address_country' => $addressCountry['value'],
            'submitted_at' => $this->pick($row, ['submit date']),
        ];

        // No email on this form: the match is a name proposal and stays pending.
        $match = NameMatcher::findBest((string) $name);

        $this->store(self::FORM_BANK, $responseId, $row, [
            'normalized' => $normalized,
            'issues' => $issues ?: null,
            'employee_id' => null,   // never linked without human confirmation
            'match_method' => $match['method'],
            'match_confidence' => $match['confidence'],
            'review_notes' => $match['employee'] !== null
                ? "Proposed match: {$match['employee']->full_name} ({$match['confidence']}% confidence). Confirm before applying."
                : 'No candidate found.',
            'review_status' => 'pending',
        ]);

        $this->stats['imported']++;
        $this->stats['needs_review']++;

        if ($issues !== []) {
            $this->stats['issues']++;
        }
    }

    // ---------------------------------------------------------------- storage

    private function alreadyImported(string $formKey, ?string $responseId): bool
    {
        if ($responseId === null) {
            return false;
        }

        return FormSubmission::where('form_key', $formKey)
            ->where('response_id', $responseId)
            ->exists();
    }

    private function store(string $formKey, ?string $responseId, array $raw, array $attributes): void
    {
        if ($this->dryRun) {
            return;
        }

        $submittedAt = $attributes['normalized']['submitted_at'] ?? null;

        FormSubmission::create(array_merge([
            'form_key' => $formKey,
            'response_id' => $responseId,
            'raw_payload' => $raw,
            'source' => 'csv_import',
            'submitted_at' => $this->parseTimestamp($submittedAt),
        ], $attributes));
    }

    private function parseTimestamp(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->toDateTimeString();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Applies a confirmed paperwork submission, creating or updating the person.
     * Bank rows go through applyBankSubmission() instead.
     */
    public function applyPaperworkSubmission(FormSubmission $submission, ?Employee $target = null): Employee
    {
        $data = $submission->normalized ?? [];

        return DB::transaction(function () use ($submission, $target, $data) {
            $employee = $target ?? $submission->employee ?? Employee::firstOrNew([
                'personal_email' => $data['personal_email'] ?? null,
            ]);

            $employee->fill(array_filter([
                'full_name' => $data['full_name'] ?? null,
                'personal_email' => $data['personal_email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'position' => $data['position'] ?? null,
                'employment_type' => $data['employment_type'] ?? null,
                'salary_amount' => isset($data['salary_amount']) ? (string) $data['salary_amount'] : null,
                'salary_currency' => $data['salary_currency'] ?? null,
                'date_of_joining' => $data['date_of_joining'] ?? null,
            ], fn ($value) => $value !== null));

            // An archived submission never implies current employment.
            $employee->status ??= EmployeeStatus::PreOnboarding;
            $employee->is_entity = in_array('payee_is_entity', $submission->issues ?? [], true);
            $employee->save();

            $submission->update([
                'employee_id' => $employee->id,
                'review_status' => 'accepted',
                'processed_at' => now(),
            ]);

            return $employee;
        });
    }

    /** Applies a confirmed bank submission to an explicitly chosen person. */
    public function applyBankSubmission(FormSubmission $submission, Employee $employee): EmployeePaymentDetail
    {
        $data = $submission->normalized ?? [];

        return DB::transaction(function () use ($submission, $employee, $data) {
            $detail = EmployeePaymentDetail::updateOrCreate(
                ['employee_id' => $employee->id],
                array_filter([
                    'payout_currency' => $data['payout_currency'] ?? null,
                    'payout_currency_raw' => $data['payout_currency_raw'] ?? null,
                    'bank_country' => $data['bank_country'] ?? null,
                    'bank_country_raw' => $data['bank_country_raw'] ?? null,
                    'bank_name' => $data['bank_name'] ?? null,
                    'account_number' => $data['account_number'] ?? null,
                    'iban' => $data['iban'] ?? null,
                    'swift_code' => $data['swift_code'] ?? null,
                    'routing_number' => $data['routing_number'] ?? null,
                    'address_line1' => $data['address_line1'] ?? null,
                    'address_line2' => $data['address_line2'] ?? null,
                    'city' => $data['city'] ?? null,
                    'state' => $data['state'] ?? null,
                    'postcode' => $data['postcode'] ?? null,
                    'address_country' => $data['address_country'] ?? null,
                    'source' => 'typeform_import',
                ], fn ($value) => $value !== null),
            );

            $submission->update([
                'employee_id' => $employee->id,
                'review_status' => 'accepted',
                'processed_at' => now(),
            ]);

            return $detail;
        });
    }
}
