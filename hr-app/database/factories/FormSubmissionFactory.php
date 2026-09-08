<?php

namespace Database\Factories;

use App\Models\FormSubmission;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class FormSubmissionFactory extends Factory
{
    protected $model = FormSubmission::class;

    public function definition(): array
    {
        return [
            'form_key' => FormSubmission::FORM_PAPERWORK,
            'response_id' => Str::random(16),
            'raw_payload' => ['Name' => fake()->name()],
            'source' => 'csv_import',
            'review_status' => 'pending',
            'match_method' => 'name_fuzzy',
            'match_confidence' => 70,
            'submitted_at' => now()->subYear(),
        ];
    }

    public function bank(): static
    {
        return $this->state(['form_key' => FormSubmission::FORM_BANK]);
    }
}
