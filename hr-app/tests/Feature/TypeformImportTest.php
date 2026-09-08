<?php

namespace Tests\Feature;

use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Models\FormSubmission;
use App\Services\Import\TypeformCsvImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TypeformImportTest extends TestCase
{
    use RefreshDatabase;

    private function writeCsv(string $name, string $contents): string
    {
        $path = storage_path("app/testing-{$name}.csv");

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }

        file_put_contents($path, $contents);

        return $path;
    }

    protected function tearDown(): void
    {
        foreach (glob(storage_path('app/testing-*.csv')) ?: [] as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    // ---------------------------------------------------------------- staging

    public function test_paperwork_rows_are_staged_with_parsed_values(): void
    {
        $csv = <<<'CSV'
        #,Name of the Onboarding Member,Personal Email ID of the Onboarding Member ,Contact Number of the Onboarding Member ,Position of the Onboarding Member,Employment Type,Other,Salary Offered,Date of Joining,Response Type,Start Date (UTC),Stage Date (UTC),Submit Date (UTC),Network ID,Tags,Ending
        abc123,Priya Menon,priya@example.com,'+91 99999 11111,Client Manager,Full-Time,,$450 USD,24th January 2023,completed,2023-01-23 06:24:12,,2023-01-23 06:27:59,938f4331f1,,
        CSV;

        $stats = (new TypeformCsvImporter)->import('paperwork', $this->writeCsv('pw', $csv));

        $this->assertSame(1, $stats['imported']);

        $submission = FormSubmission::where('form_key', 'paperwork')->sole();

        $this->assertSame('Priya Menon', $submission->normalized['full_name']);
        // Numeric comparison: the JSON cast normalises 450.0 to 450.
        $this->assertEquals(450, $submission->normalized['salary_amount']);
        $this->assertSame('USD', $submission->normalized['salary_currency']);
        $this->assertSame('2023-01-24', $submission->normalized['date_of_joining']);
        // The leading apostrophe Typeform adds to phone numbers is stripped.
        $this->assertSame('+91 99999 11111', $submission->normalized['phone']);
        $this->assertSame('pending', $submission->review_status);
    }

    public function test_test_rows_are_quarantined_not_imported(): void
    {
        $csv = <<<'CSV'
        #,Name of the Onboarding Member,Personal Email ID of the Onboarding Member ,Salary Offered,Date of Joining,Submit Date (UTC)
        r1,the previous owner Test,arthur@example.com,,test,2022-10-10 18:59:03
        r2,Real Person,real@example.com,$300 USD,5-Dec-2022,2022-12-02 08:59:21
        CSV;

        (new TypeformCsvImporter)->import('paperwork', $this->writeCsv('quarantine', $csv));

        $this->assertSame(1, FormSubmission::quarantined()->count());
        $this->assertSame(
            'the previous owner Test',
            FormSubmission::quarantined()->sole()->raw_payload['Name of the Onboarding Member'],
        );
    }

    public function test_a_row_with_a_job_title_in_the_date_field_is_flagged(): void
    {
        $csv = <<<'CSV'
        #,Name of the Onboarding Member,Personal Email ID of the Onboarding Member ,Salary Offered,Date of Joining,Submit Date (UTC)
        r9,Nadia Rahman,nadia@example.com,$400,Sales Operations Manager and Head of Sales,2022-10-17 07:47:04
        CSV;

        (new TypeformCsvImporter)->import('paperwork', $this->writeCsv('badday', $csv));

        $submission = FormSubmission::sole();

        $this->assertContains('date_not_a_date', $submission->issues);
        $this->assertNull($submission->normalized['date_of_joining']);
    }

    public function test_re_running_the_import_does_not_duplicate_rows(): void
    {
        $csv = <<<'CSV'
        #,Name of the Onboarding Member,Personal Email ID of the Onboarding Member ,Salary Offered,Date of Joining,Submit Date (UTC)
        dup1,Sana Iqbal,sana@example.com,$300 USD,5-Dec-2022,2022-12-02 08:59:21
        CSV;

        $path = $this->writeCsv('dupes', $csv);

        (new TypeformCsvImporter)->import('paperwork', $path);
        $second = (new TypeformCsvImporter)->import('paperwork', $path);

        $this->assertSame(1, FormSubmission::count());
        $this->assertSame(1, $second['duplicates']);
        $this->assertSame(0, $second['imported']);
    }

    public function test_dry_run_writes_nothing(): void
    {
        $csv = <<<'CSV'
        #,Name of the Onboarding Member,Personal Email ID of the Onboarding Member ,Salary Offered,Date of Joining,Submit Date (UTC)
        d1,Ravi Shah,ravi@example.com,$300 USD,5-Dec-2022,2022-12-02 08:59:21
        CSV;

        $stats = (new TypeformCsvImporter(dryRun: true))->import('paperwork', $this->writeCsv('dry', $csv));

        $this->assertSame(1, $stats['read']);
        $this->assertSame(0, FormSubmission::count());
    }

    // ------------------------------------------------------------------- bank

    public function test_bank_rows_are_never_auto_linked_to_a_person(): void
    {
        // A near-identical name exists, which is exactly when a careless
        // importer would link the wrong bank account.
        Employee::factory()->create(['full_name' => 'Priya Chandran']);

        $csv = <<<'CSV'
        #,Name,Address,Address line 2,City/Town,State/Region/Province,Zip/Post Code,Country,"Please mention the currency that you would like to receive your payment in ( USD , INR , PHP )",Please mention the country of your Bank where you want to receive the payment,Your bank account Number,IBAN number ,Swift Code,Response Type,Submit Date (UTC)
        b1,Priya Chandran,1 Example Road,PB Road,Kurseong,West Bengal,734203,India,INR,State Bank of India,99988877766,NA,SBININBB336,completed,2023-03-22 11:46:22
        CSV;

        (new TypeformCsvImporter)->import('bank', $this->writeCsv('bank', $csv));

        $submission = FormSubmission::where('form_key', 'bank')->sole();

        $this->assertNull($submission->employee_id, 'A bank row must never be auto-linked');
        $this->assertSame('pending', $submission->review_status);
        $this->assertFalse($submission->canAutoApply());
        $this->assertStringContainsString('Proposed match', $submission->review_notes);
    }

    public function test_an_exact_name_match_still_cannot_auto_apply_a_bank_row(): void
    {
        // Two different people can share a name exactly, and the bank form has
        // no email to tell them apart, so even 100% is not good enough.
        $submission = FormSubmission::factory()->make([
            'form_key' => FormSubmission::FORM_BANK,
            'match_method' => 'name_exact',
            'match_confidence' => 100,
        ]);

        $this->assertFalse($submission->canAutoApply());
    }

    public function test_a_deterministic_key_may_auto_apply_a_bank_row(): void
    {
        foreach (['hidden_field', 'email'] as $method) {
            $submission = FormSubmission::factory()->make([
                'form_key' => FormSubmission::FORM_BANK,
                'match_method' => $method,
                'match_confidence' => 100,
            ]);

            $this->assertTrue($submission->canAutoApply(), "[$method] should be trusted");
        }
    }

    public function test_bank_row_normalisation_reroutes_polluted_columns(): void
    {
        $csv = <<<'CSV'
        #,Name,Address,City/Town,Country,"Please mention the currency that you would like to receive your payment in ( USD , INR , PHP )",Please mention the country of your Bank where you want to receive the payment,Your bank account Number,IBAN number ,Swift Code,Submit Date (UTC)
        b2,Marta Novak,1 Example Street,Springfield,Macedonia,USD,USA,00001234567890123,061000052,BOFAUS3N,2023-03-21 10:53:09
        CSV;

        (new TypeformCsvImporter)->import('bank', $this->writeCsv('bank2', $csv));

        $submission = FormSubmission::where('form_key', 'bank')->sole();

        // The routing number in the IBAN column lands in the right field.
        $this->assertSame('061000052', $submission->normalized['routing_number']);
        $this->assertNull($submission->normalized['iban']);
        $this->assertContains('iban_contained_routing_number', $submission->issues);
    }

    public function test_bank_payload_is_encrypted_at_rest(): void
    {
        $csv = <<<'CSV'
        #,Name,Address,City/Town,Country,Your bank account Number,IBAN number ,Swift Code,Submit Date (UTC)
        b3,Ade Okafor,1 Some Street,Pune,India,50100000000000,N/A,HDFCINBB,2023-03-24 14:30:16
        CSV;

        (new TypeformCsvImporter)->import('bank', $this->writeCsv('bank3', $csv));

        $stored = DB::table('form_submissions')->value('raw_payload');

        $this->assertStringNotContainsString('50100000000000', $stored);
        $this->assertStringNotContainsString('Ade Okafor', $stored);
    }

    // ------------------------------------------------------------- applying

    public function test_applying_a_paperwork_submission_creates_a_pre_onboarding_person(): void
    {
        $csv = <<<'CSV'
        #,Name of the Onboarding Member,Personal Email ID of the Onboarding Member ,Position of the Onboarding Member,Employment Type,Salary Offered,Date of Joining,Submit Date (UTC)
        a1,Imran Sheikh,imran@example.com,Tech Executive,Full-Time,$350 USD,19th December 2022,2022-12-14 12:22:14
        CSV;

        $importer = new TypeformCsvImporter;
        $importer->import('paperwork', $this->writeCsv('apply', $csv));

        $employee = $importer->applyPaperworkSubmission(FormSubmission::sole());

        $this->assertSame('Imran Sheikh', $employee->full_name);
        $this->assertSame('350', (string) (int) $employee->salary_amount);
        $this->assertSame('2022-12-19', $employee->date_of_joining->toDateString());
        // An archived submission never implies someone works here today.
        $this->assertSame(EmployeeStatus::PreOnboarding, $employee->status);
        $this->assertSame('accepted', FormSubmission::sole()->review_status);
    }

    public function test_applying_a_bank_submission_requires_an_explicit_person(): void
    {
        $employee = Employee::factory()->create(['full_name' => 'Chosen Person']);

        $csv = <<<'CSV'
        #,Name,Address,City/Town,Country,"Please mention the currency that you would like to receive your payment in ( USD , INR , PHP )",Your bank account Number,IBAN number ,Swift Code,Submit Date (UTC)
        c1,Chosen Person,1 Road,Kolkata,India,INR,6400001234,N/A,KKBKINBB,2023-03-20 05:05:02
        CSV;

        $importer = new TypeformCsvImporter;
        $importer->import('bank', $this->writeCsv('applybank', $csv));

        $detail = $importer->applyBankSubmission(FormSubmission::sole(), $employee);

        $this->assertSame('6400001234', $detail->account_number);
        $this->assertSame('1234', $detail->account_last4);
        $this->assertSame('INR', $detail->payout_currency);
        $this->assertSame($employee->id, FormSubmission::sole()->employee_id);
    }

    public function test_company_payees_are_flagged_as_entities(): void
    {
        $csv = <<<'CSV'
        #,Name,Address,City/Town,Country,Your bank account Number,IBAN number ,Swift Code,Submit Date (UTC)
        e1,Make It Rain Ltd.,Hova House,Brighton,United Kingdom,8312473392,N/A,CMFGUS33,2023-03-22 11:35:55
        CSV;

        (new TypeformCsvImporter)->import('bank', $this->writeCsv('entity', $csv));

        $this->assertContains('payee_is_entity', FormSubmission::sole()->issues);
    }
}
