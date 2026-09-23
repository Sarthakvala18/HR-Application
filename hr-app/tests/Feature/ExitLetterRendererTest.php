<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Employee;
use App\Services\Letters\ExitLetterRenderer;
use App\Services\Letters\LetterContent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use RuntimeException;
use setasign\Fpdi\Fpdi;
use Tests\Concerns\MakesLetterhead;
use Tests\TestCase;

class ExitLetterRendererTest extends TestCase
{
    use MakesLetterhead;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeLetterhead();
    }

    /** Reads the page count back out of a generated PDF. */
    private function pageCountOf(string $pdf): int
    {
        $path = tempnam(sys_get_temp_dir(), 'letter').'.pdf';
        file_put_contents($path, $pdf);

        try {
            return (new Fpdi)->setSourceFile($path);
        } finally {
            @unlink($path);
        }
    }

    private function employee(array $overrides = []): Employee
    {
        $department = Department::factory()->create(['name' => 'Tech']);

        return Employee::factory()->create(array_merge([
            'full_name' => 'Test Candidate',
            'employee_code' => 'CF9999',
            'position' => 'Software Engineer',
            'department_id' => $department->id,
            'date_of_joining' => '2024-01-15',
            'date_of_exit' => '2026-09-15',
        ], $overrides));
    }

    /** Values the record cannot supply, which a caller passes in. */
    private function context(array $overrides = []): array
    {
        return array_merge([
            'report_to' => 'Engineering Lead',
            'hr_name' => 'HR Department',
        ], $overrides);
    }

    public function test_it_renders_both_letters_for_a_complete_record(): void
    {
        $employee = $this->employee();
        $renderer = app(ExitLetterRenderer::class);

        foreach ([LetterContent::RELIEVING, LetterContent::EXPERIENCE] as $type) {
            $pdf = $renderer->render($employee, $type, $this->context());

            $this->assertStringStartsWith('%PDF-', $pdf);

            // Byte length depends on the letterhead artwork, so assert the
            // structure instead: a real letter has at least one laid-out page.
            $this->assertGreaterThanOrEqual(
                1,
                $this->pageCountOf($pdf),
                "The {$type} letter has no pages."
            );
        }
    }

    public function test_it_reports_missing_values_rather_than_rendering_a_gap(): void
    {
        $employee = $this->employee(['date_of_exit' => null, 'position' => null]);
        $renderer = app(ExitLetterRenderer::class);

        $missing = $renderer->missingFor($employee, LetterContent::RELIEVING, ['hr_name' => 'HR']);

        $this->assertContains('last_date', $missing);
        $this->assertContains('position', $missing);
        $this->assertContains('report_to', $missing);
    }

    public function test_it_refuses_to_render_when_a_value_is_missing(): void
    {
        $employee = $this->employee(['date_of_exit' => null]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/missing .*last_date/');

        app(ExitLetterRenderer::class)->render($employee, LetterContent::RELIEVING, $this->context());
    }

    public function test_the_address_row_is_dropped_when_no_address_is_supplied(): void
    {
        $content = app(LetterContent::class);

        $blocks = $content->blocks(LetterContent::RELIEVING, $this->values(['address' => '']));
        $meta = collect($blocks)->firstWhere('type', 'meta');

        $this->assertArrayNotHasKey('Employee Address', $meta['rows']);
        $this->assertSame('Test Candidate', $meta['rows']['Employee Name']);
    }

    public function test_the_address_row_appears_when_one_is_supplied(): void
    {
        $content = app(LetterContent::class);

        $blocks = $content->blocks(LetterContent::RELIEVING, $this->values(['address' => '12 Example Road']));
        $meta = collect($blocks)->firstWhere('type', 'meta');

        $this->assertSame('12 Example Road', $meta['rows']['Employee Address']);
    }

    public function test_every_date_in_a_letter_uses_one_format(): void
    {
        $employee = $this->employee();
        $renderer = app(ExitLetterRenderer::class);
        $content = app(LetterContent::class);

        // Deliberately hand in an ISO date the way the CLI does.
        $blocks = $content->blocks(
            LetterContent::EXPERIENCE,
            $this->valuesFor($employee, $renderer, ['last_date' => '2026-09-15']),
        );

        $prose = collect($blocks)
            ->where('type', 'p')
            ->pluck('text')
            ->implode(' ');

        // Joined 2024-01-15, exited 2026-09-15 — both handed in as ISO and
        // both expected out in the single letter format.
        $this->assertStringContainsString('15 January 2024', $prose);
        $this->assertStringContainsString('15 September 2026', $prose);
        $this->assertStringNotContainsString('2024-01-15', $prose, 'An ISO date leaked into the letter.');
        $this->assertStringNotContainsString('2026-09-15', $prose, 'An ISO date leaked into the letter.');
        $this->assertStringNotContainsString('15/01/2024', $prose, 'A second date format leaked into the letter.');
    }

    public function test_the_contact_address_never_falls_back_to_the_framework_placeholder(): void
    {
        config(['mail.from.address' => 'hello@example.com']);

        $employee = $this->employee();
        $content = app(LetterContent::class);

        $blocks = $content->blocks(
            LetterContent::RELIEVING,
            $this->valuesFor($employee, app(ExitLetterRenderer::class)),
        );

        $prose = collect($blocks)->where('type', 'p')->pluck('text')->implode(' ');

        $this->assertStringNotContainsString('example.com', $prose);
        $this->assertStringContainsString('hr@coachfoundation.com', $prose);
    }

    public function test_an_unknown_team_is_refused_rather_than_given_the_wrong_job_description(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/No letter copy for the .Legal. team/');

        app(LetterContent::class)->blocks(LetterContent::RELIEVING, $this->values(['team' => 'Legal']));
    }

    public function test_the_tech_and_operations_letters_describe_different_work(): void
    {
        $content = app(LetterContent::class);

        $tech = collect($content->blocks(LetterContent::RELIEVING, $this->values(['team' => 'Tech'])))
            ->firstWhere('type', 'bullets')['items'];

        $ops = collect($content->blocks(LetterContent::RELIEVING, $this->values(['team' => 'Operations'])))
            ->firstWhere('type', 'bullets')['items'];

        $this->assertNotEquals($tech, $ops);
        $this->assertStringContainsString('tracking mechanisms', implode(' ', $tech));
        $this->assertStringContainsString('day-to-day business operations', implode(' ', $ops));
    }

    public function test_no_employee_name_is_hardcoded_in_the_letter_copy(): void
    {
        // The Zoho experience template had one employee's name baked into its
        // body text, so every other employee received a letter naming him.
        $content = app(LetterContent::class);

        foreach ([LetterContent::RELIEVING, LetterContent::EXPERIENCE] as $type) {
            foreach (['Tech', 'Operations'] as $team) {
                $blocks = $content->blocks($type, $this->values([
                    'team' => $team,
                    'name' => 'Placeholder Person',
                ]));

                $all = json_encode($blocks);

                $this->assertStringNotContainsString('Sarthak', $all);
                $this->assertStringContainsString('Placeholder Person', $all);
            }
        }
    }

    public function test_filenames_are_distinct_per_letter_type(): void
    {
        $employee = $this->employee();
        $renderer = app(ExitLetterRenderer::class);

        $this->assertNotSame(
            $renderer->filename($employee, LetterContent::RELIEVING),
            $renderer->filename($employee, LetterContent::EXPERIENCE),
        );
    }

    /**
     * A complete value set, so a test can vary one field at a time.
     *
     * @return array<string, string>
     */
    private function values(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Test Candidate',
            'employee_id' => 'CF9999',
            'address' => '',
            'position' => 'Software Engineer',
            'team' => 'Tech',
            'report_to' => 'Engineering Lead',
            'join_date' => '15 July 2024',
            'last_date' => '15 September 2026',
            'letter_date' => '15 September 2026',
            'hr_name' => 'HR Department',
            'hr_email' => 'hr@coachfoundation.com',
        ], $overrides);
    }

    /**
     * The renderer's own value mapping, reached through a render so the test
     * exercises the real normalisation rather than a copy of it.
     *
     * @return array<string, string>
     */
    private function valuesFor(Employee $employee, ExitLetterRenderer $renderer, array $context = []): array
    {
        $method = new \ReflectionMethod($renderer, 'values');

        return $method->invoke($renderer, $employee, $this->context($context));
    }
}
