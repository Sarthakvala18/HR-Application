<?php

namespace App\Console\Commands;

use App\Models\FormSubmission;
use App\Services\Import\TypeformCsvImporter;
use Illuminate\Console\Command;
use Throwable;

class ImportTypeformCsv extends Command
{
    protected $signature = 'hr:import-typeform
        {form : paperwork or bank}
        {path : Path to the CSV export}
        {--dry-run : Parse and report without writing anything}';

    protected $description = 'Import a historical Typeform CSV export into the review queue';

    public function handle(): int
    {
        $form = strtolower((string) $this->argument('form'));
        $path = (string) $this->argument('path');
        $dryRun = (bool) $this->option('dry-run');

        if (! in_array($form, [TypeformCsvImporter::FORM_PAPERWORK, TypeformCsvImporter::FORM_BANK], true)) {
            $this->error('Form must be "paperwork" or "bank".');

            return self::FAILURE;
        }

        if ($form === TypeformCsvImporter::FORM_BANK && ! $dryRun) {
            $this->warn('Bank submissions contain account numbers and home addresses.');
            $this->warn('They will be stored encrypted and left unlinked until reviewed.');

            if (! $this->confirm('Continue?', true)) {
                return self::SUCCESS;
            }
        }

        $this->info($dryRun ? "Dry run: parsing {$path}" : "Importing {$path}");

        try {
            $stats = (new TypeformCsvImporter(dryRun: $dryRun))->import($form, $path);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->table(['Metric', 'Count'], collect($stats)->map(
            fn ($count, $metric) => [str_replace('_', ' ', ucfirst($metric)), $count],
        )->values()->all());

        if ($dryRun) {
            $this->newLine();
            $this->comment('Dry run: nothing was written.');

            return self::SUCCESS;
        }

        $this->reportIssues($form);

        $this->newLine();
        $this->info('Rows are staged. Nothing has been applied to employee records yet.');
        $this->line('Review them in the app: Import review queue.');

        return self::SUCCESS;
    }

    /** Show which data-quality problems actually appeared, and how often. */
    private function reportIssues(string $form): void
    {
        $counts = [];

        FormSubmission::where('form_key', $form)
            ->whereNotNull('issues')
            ->select('issues')
            ->get()
            ->each(function (FormSubmission $submission) use (&$counts) {
                foreach ($submission->issues ?? [] as $issue) {
                    $counts[$issue] = ($counts[$issue] ?? 0) + 1;
                }
            });

        if ($counts === []) {
            return;
        }

        arsort($counts);

        $this->newLine();
        $this->comment('Data-quality flags raised:');
        $this->table(
            ['Issue', 'Rows'],
            collect($counts)->map(fn ($count, $issue) => [$issue, $count])->values()->all(),
        );
    }
}
