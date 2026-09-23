<?php

namespace Tests\Feature;

use App\Enums\EmployeeStatus;
use App\Models\Department;
use App\Models\DocumentTemplate;
use App\Models\Employee;
use App\Services\Process\OffboardingRunBuilder;
use App\Services\Zoho\LetterService;
use App\Services\Zoho\ZohoSignClient;
use Database\Seeders\DocumentTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class ZohoSignTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->seed(DocumentTemplateSeeder::class);

        config()->set('zoho.client_id', 'test-client');
        config()->set('zoho.client_secret', 'test-secret');
        config()->set('zoho.refresh_token', 'test-refresh');
    }

    private int $codeSequence = 9000;

    private function leaver(string $departmentKey, array $attributes = []): Employee
    {
        return Employee::factory()->create(array_merge([
            'department_id' => Department::where('key', $departmentKey)->first()->id,
            'employee_code' => 'CF-'.(++$this->codeSequence),
            'position' => 'Tech Executive',
            'date_of_joining' => '2023-12-19',
            'date_of_exit' => '2026-09-30',
            'status' => EmployeeStatus::Offboarding,
        ], $attributes));
    }

    // ------------------------------------------------------ template routing

    public function test_each_department_resolves_its_own_relieving_template(): void
    {
        $service = app(LetterService::class);

        $this->assertSame(
            'Relieving Letter tech detailed',
            $service->templateFor($this->leaver('tech'))->name,
        );

        $this->assertSame(
            'Relieving Letter operations detailed',
            $service->templateFor($this->leaver('operations'))->name,
        );
    }

    public function test_a_department_with_no_template_raises_a_clear_error(): void
    {
        $employee = $this->leaver('finance');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No relieving template is registered');

        app(LetterService::class)->templateFor($employee);
    }

    // -------------------------------------------------------- field mapping

    /**
     * The two templates use different labels for the same logical values, so
     * the payload must follow the template rather than a fixed convention.
     */
    public function test_tech_payload_uses_that_templates_exact_labels(): void
    {
        $employee = $this->leaver('tech', ['full_name' => 'Ravi Kumar']);
        $service = app(LetterService::class);

        $payload = $service->buildPayload($employee, $service->templateFor($employee));

        $this->assertSame($employee->employee_code, $payload['text']['Employee ID']);
        $this->assertSame('Tech Executive', $payload['text']['Job Title']);
        $this->assertArrayHasKey('Join Date', $payload['dates']);
        $this->assertArrayHasKey('Last Date', $payload['dates']);

        // Capital-D "Join Date" here, lowercase in the operations template.
        $this->assertArrayNotHasKey('Join date', $payload['dates']);
        $this->assertArrayNotHasKey('End Date', $payload['dates']);
    }

    public function test_operations_payload_uses_that_templates_exact_labels(): void
    {
        $employee = $this->leaver('operations', ['full_name' => 'Anita Rao']);
        $service = app(LetterService::class);

        $payload = $service->buildPayload($employee, $service->templateFor($employee));

        $this->assertSame('Anita Rao', $payload['text']['Full name']);
        $this->assertArrayHasKey('Join date', $payload['dates']);
        $this->assertArrayHasKey('End Date', $payload['dates']);
        $this->assertArrayNotHasKey('Last Date', $payload['dates']);
    }

    /**
     * The tech template has no name field, so the employee's name cannot appear
     * in that letter. Pinned as a test so the gap is visible rather than silent.
     */
    public function test_the_tech_template_has_no_name_field_and_reports_it(): void
    {
        $employee = $this->leaver('tech', ['full_name' => 'Ravi Kumar']);
        $service = app(LetterService::class);

        $payload = $service->buildPayload($employee, $service->templateFor($employee));

        $this->assertNotContains('Full name', array_keys($payload['text']));
        $this->assertContains('full_name', $payload['unmapped']);
    }

    public function test_missing_required_values_are_reported_not_silently_sent(): void
    {
        $employee = $this->leaver('tech', ['employee_code' => null, 'position' => null]);
        $service = app(LetterService::class);

        $payload = $service->buildPayload($employee, $service->templateFor($employee));

        $this->assertContains('employee_id', $payload['missing']);
        $this->assertContains('job_title', $payload['missing']);
    }

    public function test_send_refuses_when_a_required_value_is_missing(): void
    {
        Http::fake();

        $employee = $this->leaver('tech', ['employee_code' => null]);
        DocumentTemplate::query()->update(['zoho_template_id' => 'tpl-123', 'verified_at' => now()]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('missing employee_id');

        app(LetterService::class)->send($employee);

        Http::assertNothingSent();
    }

    public function test_send_refuses_when_the_template_has_no_zoho_id(): void
    {
        Http::fake();

        DocumentTemplate::query()->update(['zoho_template_id' => null]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no Zoho template id');

        app(LetterService::class)->send($this->leaver('tech'));
    }

    // ------------------------------------------------------------- API layer

    public function test_a_send_posts_the_mapped_fields_to_zoho(): void
    {
        Http::fake([
            'accounts.zoho.com/*' => Http::response(['access_token' => 'fresh-token', 'expires_in' => 3600]),
            'sign.zoho.com/api/v1/templates/*/createdocument' => Http::response([
                'status' => 'success',
                'requests' => ['request_id' => 'req-77', 'request_status' => 'inprogress'],
            ]),
            // Recipient slots are read from the template so each action can
            // carry the action_id Zoho requires.
            'sign.zoho.com/api/v1/templates/*' => Http::response([
                'status' => 'success',
                'templates' => [
                    'actions' => [[
                        'action_id' => 'act-1',
                        'action_type' => 'SIGN',
                        'recipient_name' => '',
                        'recipient_email' => '',
                    ]],
                ],
            ]),
        ]);

        $employee = $this->leaver('operations', [
            'full_name' => 'Anita Rao',
            'personal_email' => 'anita@example.com',
        ]);
        DocumentTemplate::query()->update(['zoho_template_id' => 'tpl-ops-1', 'verified_at' => now()]);

        $result = app(LetterService::class)->send($employee, quickSend: true);

        $this->assertSame('req-77', $result['request_id']);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'createdocument')) {
                return false;
            }

            $body = (string) $request->body();

            return str_contains($body, 'Full name')
                && str_contains($body, 'Anita Rao')
                && str_contains($body, 'End Date')
                && str_contains($body, 'anita@example.com');
        });
    }

    public function test_the_access_token_is_refreshed_rather_than_reused_from_env(): void
    {
        Http::fake([
            'accounts.zoho.com/*' => Http::response(['access_token' => 'fresh-token']),
            'sign.zoho.com/*' => Http::response(['status' => 'success', 'templates' => []]),
        ]);

        app(ZohoSignClient::class)->listTemplates();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'accounts.zoho.com')
            && $request['grant_type'] === 'refresh_token');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'sign.zoho.com')
            && $request->hasHeader('Authorization', 'Zoho-oauthtoken fresh-token'));
    }

    public function test_ping_reports_an_auth_failure_clearly_instead_of_throwing(): void
    {
        Http::fake([
            'accounts.zoho.com/*' => Http::response(['access_token' => 'stale']),
            'sign.zoho.com/*' => Http::response([
                'code' => 9041, 'message' => 'Invalid Oauth token', 'status' => 'failure',
            ], 401),
        ]);

        $result = app(ZohoSignClient::class)->ping();

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Invalid Oauth token', $result['detail']);
    }

    public function test_missing_credentials_produce_an_actionable_message(): void
    {
        config()->set('zoho.client_id', null);
        config()->set('zoho.refresh_token', null);
        config()->set('zoho.access_token_override', null);

        $result = app(ZohoSignClient::class)->ping();

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('not configured', $result['detail']);
    }

    public function test_field_names_are_extracted_from_a_template_definition(): void
    {
        Http::fake([
            'accounts.zoho.com/*' => Http::response(['access_token' => 'fresh-token']),
            'sign.zoho.com/api/v1/templates/tpl-ops-1' => Http::response([
                'status' => 'success',
                'templates' => [
                    'document_fields' => [[
                        'fields' => [
                            ['field_label' => 'Full name'],
                            ['field_label' => 'Employee ID'],
                            ['field_label' => 'End Date'],
                        ],
                    ]],
                ],
            ]),
        ]);

        $fields = app(ZohoSignClient::class)->templateFieldNames('tpl-ops-1');

        $this->assertSame(['Full name', 'Employee ID', 'End Date'], $fields);
    }

    // ------------------------------------------------------ experience letters

    public function test_each_department_resolves_its_own_experience_template(): void
    {
        $service = app(LetterService::class);

        $this->assertSame(
            'Experience Letter Tech',
            $service->templateFor($this->leaver('tech'), DocumentTemplate::TYPE_EXPERIENCE)->name,
        );

        $this->assertSame(
            'Experience Letter - Operations',
            $service->templateFor($this->leaver('operations'), DocumentTemplate::TYPE_EXPERIENCE)->name,
        );
    }

    /**
     * The experience letter calls the position "Role" where the relieving
     * letter calls it "Job Title", and uses different date labels again.
     */
    public function test_experience_letter_uses_its_own_vocabulary(): void
    {
        $employee = $this->leaver('tech', ['full_name' => 'Ravi Kumar']);
        $service = app(LetterService::class);

        $payload = $service->buildPayload(
            $employee,
            $service->templateFor($employee, DocumentTemplate::TYPE_EXPERIENCE),
            ['hr_name' => 'a department head'],
        );

        $this->assertSame('Ravi Kumar', $payload['text']['Full name']);
        $this->assertSame('Tech Executive', $payload['text']['Role']);
        $this->assertSame('a department head', $payload['text']['HR Name']);
        $this->assertArrayHasKey('Joining date', $payload['dates']);
        $this->assertArrayHasKey('Leaving date', $payload['dates']);

        // Relieving-letter labels must not leak into the experience payload.
        $this->assertArrayNotHasKey('Job Title', $payload['text']);
        $this->assertArrayNotHasKey('Join Date', $payload['dates']);
        $this->assertArrayNotHasKey('Last Date', $payload['dates']);
    }

    /**
     * Signature fields are drawn during the signing ceremony, so the API must
     * neither send a value for them nor treat them as missing data.
     */
    public function test_signature_fields_are_excluded_from_the_payload_not_reported_missing(): void
    {
        $employee = $this->leaver('tech');
        $service = app(LetterService::class);

        $payload = $service->buildPayload(
            $employee,
            $service->templateFor($employee, DocumentTemplate::TYPE_EXPERIENCE),
            ['hr_name' => 'a department head'],
        );

        $this->assertContains('Signature', $payload['signatures']);
        $this->assertArrayNotHasKey('Signature', $payload['text']);
        $this->assertArrayNotHasKey('Signature', $payload['dates']);
        $this->assertNotContains('signature', $payload['missing']);
    }

    public function test_hr_name_is_required_and_reported_when_absent(): void
    {
        config()->set('zoho.sign.hr_name', null);

        $employee = $this->leaver('tech');
        $service = app(LetterService::class);

        $payload = $service->buildPayload(
            $employee,
            $service->templateFor($employee, DocumentTemplate::TYPE_EXPERIENCE),
        );

        $this->assertContains('hr_name', $payload['missing']);
    }

    /**
     * "role" and "job_title" are the same underlying value, so a template that
     * maps one must not report the other as an unmapped gap.
     */
    public function test_alias_labels_are_not_reported_as_unmapped(): void
    {
        $employee = $this->leaver('tech');
        $service = app(LetterService::class);

        $payload = $service->buildPayload(
            $employee,
            $service->templateFor($employee, DocumentTemplate::TYPE_EXPERIENCE),
            ['hr_name' => 'a department head'],
        );

        $this->assertNotContains('job_title', $payload['unmapped']);
        $this->assertNotContains('join_date', $payload['unmapped']);
        $this->assertNotContains('last_date', $payload['unmapped']);
    }

    // -------------------------------------------------- unconfirmed templates

    /**
     * Confirmed against the live API: the two experience letters share a field
     * set, unlike the relieving pair. Pinned so a future divergence is caught.
     */
    public function test_both_experience_templates_share_the_same_field_set(): void
    {
        $tech = DocumentTemplate::resolve(
            DocumentTemplate::TYPE_EXPERIENCE,
            Department::where('key', 'tech')->first()->id,
        );
        $operations = DocumentTemplate::resolve(
            DocumentTemplate::TYPE_EXPERIENCE,
            Department::where('key', 'operations')->first()->id,
        );

        $this->assertSame($tech->field_map, $operations->field_map);
        $this->assertTrue($tech->isReady());
        $this->assertTrue($operations->isReady());
    }

    /** The relieving pair, by contrast, genuinely differ. */
    public function test_the_relieving_templates_do_not_share_a_field_set(): void
    {
        $tech = DocumentTemplate::resolve(
            DocumentTemplate::TYPE_RELIEVING,
            Department::where('key', 'tech')->first()->id,
        );
        $operations = DocumentTemplate::resolve(
            DocumentTemplate::TYPE_RELIEVING,
            Department::where('key', 'operations')->first()->id,
        );

        $this->assertNotSame($tech->field_map, $operations->field_map);
        $this->assertArrayNotHasKey('full_name', $tech->field_map);
        $this->assertArrayHasKey('full_name', $operations->field_map);
    }

    /**
     * Zoho owns Name, Date and Signature fields. The routing must follow the
     * recorded field type, not the label or a hand-maintained list.
     */
    public function test_field_type_decides_how_a_field_is_filled(): void
    {
        $employee = $this->leaver('operations', ['full_name' => 'Anita Rao']);
        $service = app(LetterService::class);
        $template = $service->templateFor($employee);

        $template->update(['field_types' => [
            'Full name' => 'Name',
            'Employee ID' => 'Textfield',
            'Job Title' => 'Textfield',
            'Join date' => 'CustomDate',
            'End Date' => 'CustomDate',
        ]]);

        $payload = $service->buildPayload($employee, $template->refresh());

        // A Name field is populated by Zoho from the recipient, so we must not
        // send it as text even though we hold the value.
        $this->assertArrayNotHasKey('Full name', $payload['text']);
        $this->assertContains('Full name', $payload['signatures']);
        $this->assertArrayHasKey('Employee ID', $payload['text']);
        $this->assertArrayHasKey('Join date', $payload['dates']);
        $this->assertNotContains('full_name', $payload['missing']);
    }

    public function test_an_unverified_template_cannot_be_sent(): void
    {
        Http::fake();

        $employee = $this->leaver('tech', ['personal_email' => 'ravi@example.com']);
        DocumentTemplate::query()->update([
            'zoho_template_id' => 'tpl-1',
            'verified_at' => null,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not been verified');

        app(LetterService::class)->send($employee, DocumentTemplate::TYPE_RELIEVING);

        Http::assertNothingSent();
    }

    // ----------------------------------------------------- offboarding wiring

    public function test_the_offboarding_letters_step_names_both_documents(): void
    {
        $employee = $this->leaver('tech');

        $run = app(OffboardingRunBuilder::class)->build($employee);
        $letters = $run->tasks->firstWhere('key', 'letters');

        $this->assertStringContainsString('Relieving Letter tech detailed', $letters->description_md);
        $this->assertStringContainsString('Experience Letter Tech', $letters->description_md);
        $this->assertCount(2, $letters->payload['document_template_ids']);
    }

    public function test_the_letters_step_flags_an_unverified_template(): void
    {
        $employee = $this->leaver('operations');

        $run = app(OffboardingRunBuilder::class)->build($employee);
        $letters = $run->tasks->firstWhere('key', 'letters');

        $this->assertStringContainsString('not verified against the Zoho API', $letters->description_md);
    }

    public function test_the_letters_step_reports_ready_once_a_template_is_verified(): void
    {
        DocumentTemplate::query()->update(['verified_at' => now()]);

        $employee = $this->leaver('tech');

        $run = app(OffboardingRunBuilder::class)->build($employee);
        $letters = $run->tasks->firstWhere('key', 'letters');

        $this->assertStringContainsString('ready to send', $letters->description_md);
    }

    // ------------------------------------------------------- sending both

    private function readyTemplates(): void
    {
        DocumentTemplate::query()->update([
            'zoho_template_id' => 'tpl-1',
            'verified_at' => now(),
        ]);
    }

    private function fakeZohoAccepting(): void
    {
        Http::fake([
            'accounts.zoho.com/*' => Http::response(['access_token' => 'fresh-token']),
            'sign.zoho.com/api/v1/templates/*/createdocument' => Http::response([
                'status' => 'success',
                'requests' => ['request_id' => 'req-1', 'request_status' => 'inprogress'],
            ]),
            'sign.zoho.com/api/v1/templates/*' => Http::response([
                'status' => 'success',
                'templates' => ['actions' => [[
                    'action_id' => 'act-1',
                    'action_type' => 'SIGN',
                    'recipient_name' => '',
                    'recipient_email' => '',
                ]]],
            ]),
        ]);
    }

    public function test_sending_exit_letters_dispatches_both_documents(): void
    {
        $this->fakeZohoAccepting();
        $this->readyTemplates();

        $employee = $this->leaver('operations', ['personal_email' => 'anita@example.com']);

        $results = app(LetterService::class)->sendExitLetters($employee, quickSend: true);

        $this->assertCount(2, $results);
        $this->assertEqualsCanonicalizing(
            [DocumentTemplate::TYPE_RELIEVING, DocumentTemplate::TYPE_EXPERIENCE],
            array_column($results, 'type'),
        );
        $this->assertSame([true, true], array_column($results, 'ok'));
    }

    /**
     * A failure on one letter must not hide that the other already went out,
     * so each result is reported separately rather than collapsed.
     */
    public function test_one_failing_letter_does_not_hide_the_one_that_sent(): void
    {
        $this->fakeZohoAccepting();
        $this->readyTemplates();

        // The tech relieving letter needs a manager; leave that unset so it
        // fails while the experience letter still succeeds.
        $employee = $this->leaver('tech', [
            'personal_email' => 'ravi@example.com',
            'manager_id' => null,
        ]);

        $results = app(LetterService::class)->sendExitLetters($employee, quickSend: true);

        $byType = collect($results)->keyBy('type');

        $this->assertFalse($byType[DocumentTemplate::TYPE_RELIEVING]['ok']);
        $this->assertStringContainsString('report_to', $byType[DocumentTemplate::TYPE_RELIEVING]['error']);
        $this->assertTrue($byType[DocumentTemplate::TYPE_EXPERIENCE]['ok']);
    }

    public function test_a_licence_failure_is_reported_per_letter_not_as_a_crash(): void
    {
        Http::fake([
            'accounts.zoho.com/*' => Http::response(['access_token' => 'fresh-token']),
            'sign.zoho.com/api/v1/templates/*/createdocument' => Http::response([
                'status' => 'failure',
                'message' => 'Upgrade Zoho Sign license to send documents via API.',
            ], 400),
            'sign.zoho.com/api/v1/templates/*' => Http::response([
                'status' => 'success',
                'templates' => ['actions' => [['action_id' => 'act-1', 'action_type' => 'SIGN']]],
            ]),
        ]);
        $this->readyTemplates();

        $employee = $this->leaver('operations', ['personal_email' => 'anita@example.com']);

        $results = app(LetterService::class)->sendExitLetters($employee, quickSend: true);

        $this->assertSame([false, false], array_column($results, 'ok'));
        foreach ($results as $result) {
            $this->assertStringContainsString('plan does not allow sending', $result['error']);
        }
    }

    // The two pipeline-send tests that lived here moved to
    // LetterSendScreenTest: the offboarding step now composes letters on the
    // company letterhead and emails them, so asserting it from a Zoho test
    // implied an integration that is no longer in that path.

    // --------------------------------------------- filling gaps at send time

    public function test_missing_fields_are_reported_across_both_letters(): void
    {
        $employee = $this->leaver('tech', ['manager_id' => null]);

        $missing = app(LetterService::class)->missingFor($employee);

        // The tech relieving letter needs a manager name; nothing else is short.
        $this->assertContains('report_to', $missing);
    }

    /**
     * The same underlying gap must be asked for once, not once per label.
     */
    public function test_aliased_gaps_are_only_asked_for_once(): void
    {
        $employee = $this->leaver('operations', ['date_of_exit' => null]);

        $missing = app(LetterService::class)->missingFor($employee);

        $dateKeys = array_intersect($missing, ['last_date', 'leaving_date']);

        $this->assertCount(1, $dateKeys, 'The last working day was asked for twice');
    }

    public function test_a_supplied_value_closes_the_gap_without_editing_the_record(): void
    {
        $employee = $this->leaver('tech', ['manager_id' => null]);
        $service = app(LetterService::class);

        $this->assertNotEmpty($service->missingFor($employee));
        $this->assertSame([], $service->missingFor($employee, ['report_to' => 'Mehak Bhatia']));
    }

    /** Filling one alias fills its partners, since they carry the same value. */
    public function test_supplying_one_alias_satisfies_the_others(): void
    {
        $employee = $this->leaver('operations', ['date_of_exit' => null]);
        $service = app(LetterService::class);

        $values = $service->logicalValues($employee, ['leaving_date' => '30/09/2026']);

        $this->assertSame('30/09/2026', $values['leaving_date']);
        $this->assertSame('30/09/2026', $values['last_date']);
    }

    public function test_supplied_values_reach_the_zoho_payload(): void
    {
        $this->fakeZohoAccepting();
        $this->readyTemplates();

        $employee = $this->leaver('tech', [
            'manager_id' => null,
            'personal_email' => 'ravi@example.com',
        ]);

        $results = app(LetterService::class)->sendExitLetters(
            $employee,
            quickSend: true,
            context: ['report_to' => 'Mehak Bhatia'],
        );

        $this->assertSame([true, true], array_column($results, 'ok'));

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'createdocument')
                && str_contains((string) $request->body(), 'Mehak Bhatia');
        });
    }
}
