<?php

namespace App\Console\Commands;

use App\Models\FormSubmission;
use App\Services\Import\NameMatcher;
use Illuminate\Console\Command;

/**
 * Recomputes match proposals for pending submissions.
 *
 * Match confidence is calculated at import time against whoever existed then.
 * Because paperwork rows create people only once a human accepts them, bank
 * rows imported first are scored against an empty directory and would sit at
 * 0% forever. Re-running this after accepting paperwork refreshes the
 * proposals so the queue tells the truth.
 */
class RematchSubmissions extends Command
{
    protected $signature = 'hr:rematch {--form= : Limit to paperwork or bank}';

    protected $description = 'Recompute match proposals for pending form submissions';

    public function handle(): int
    {
        $query = FormSubmission::query()->where('review_status', 'pending');

        if ($form = $this->option('form')) {
            $query->where('form_key', $form);
        }

        $submissions = $query->get();

        if ($submissions->isEmpty()) {
            $this->info('Nothing pending to re-match.');

            return self::SUCCESS;
        }

        $improved = 0;

        foreach ($submissions as $submission) {
            $name = (string) ($submission->normalized['full_name'] ?? $submission->raw_payload['Name'] ?? '');

            if (trim($name) === '') {
                continue;
            }

            $email = $submission->normalized['personal_email'] ?? null;
            $match = NameMatcher::findBest($name, $email);

            $previous = $submission->match_confidence ?? 0;

            $submission->update([
                'match_method' => $match['method'],
                'match_confidence' => $match['confidence'],
                'review_notes' => $match['employee'] !== null
                    ? "Proposed match: {$match['employee']->full_name} ({$match['confidence']}% confidence). Confirm before applying."
                    : 'No candidate found.',
                // Paperwork rows may link on a deterministic key; bank rows never do.
                'employee_id' => $submission->form_key === FormSubmission::FORM_BANK
                    ? null
                    : ($match['method'] === 'email' ? $match['employee']?->id : $submission->employee_id),
            ]);

            if ($match['confidence'] > $previous) {
                $improved++;
            }
        }

        $this->info("Re-matched {$submissions->count()} submission(s); {$improved} improved.");
        $this->comment('Bank rows still require explicit human confirmation before they are applied.');

        return self::SUCCESS;
    }
}
