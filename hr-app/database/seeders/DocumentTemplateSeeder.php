<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\DocumentTemplate;
use Illuminate\Database\Seeder;

/**
 * The exit-letter templates that exist in Zoho Sign.
 *
 * Field labels are transcribed from the templates themselves. They do NOT share
 * a vocabulary across letters or departments:
 *
 *   Relieving, tech        Employee ID, Job Title, Report to, Join Date, Last Date
 *   Relieving, operations  Full name, Employee ID, Job Title, Join date, End Date
 *   Experience, tech       Full name, Role, Joining date, Leaving date, HR Name, Signature
 *   Experience, operations unknown — not yet transcribed
 *
 * So the position is "Job Title" on one letter and "Role" on another, and each
 * of the four uses a different pair of date labels. Nothing here is trusted for
 * a real send until `hr:zoho-sign verify` confirms it against the live API.
 */
class DocumentTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $departments = Department::pluck('id', 'key');

        foreach ($this->templates() as $template) {
            DocumentTemplate::updateOrCreate(
                [
                    'type' => $template['type'],
                    'department_id' => $departments[$template['department']] ?? null,
                ],
                [
                    'name' => $template['name'],
                    'zoho_template_id' => $template['zoho_template_id'] ?? null,
                    'field_map' => $template['field_map'],
                    'date_fields' => $template['date_fields'] ?? [],
                    'signature_fields' => $template['signature_fields'] ?? [],
                    'notes' => $template['notes'] ?? null,
                    'active' => true,
                ],
            );
        }
    }

    private function templates(): array
    {
        // Confirmed against the live templates on 2026-09-08. Field types are
        // recorded by `hr:zoho-sign verify`; only Textfield and CustomDate are
        // supplied by us. Full name (Name), Sign date (Date) and Signature are
        // filled by Zoho from the recipient or during signing.
        $experienceFields = [
            'full_name' => 'Full name',
            'role' => 'Role',
            'joining_date' => 'Joining date',
            'leaving_date' => 'Leaving date',
            'hr_name' => 'HR Name',
            'signature' => 'Signature',
        ];

        return [
            // ------------------------------------------------ relieving letters
            [
                'type' => DocumentTemplate::TYPE_RELIEVING,
                'department' => 'tech',
                'name' => 'Relieving Letter tech detailed',
                'zoho_template_id' => config('zoho.sign.templates.relieving.tech'),
                'field_map' => [
                    'employee_id' => 'Employee ID',
                    'job_title' => 'Job Title',
                    'report_to' => 'Report to',
                    'join_date' => 'Join Date',
                    'last_date' => 'Last Date',
                ],
                'date_fields' => ['join_date', 'last_date'],
                'notes' => 'This template has no name field at all, so the letter cannot state who it is about unless the name is in the body text.',
            ],
            [
                'type' => DocumentTemplate::TYPE_RELIEVING,
                'department' => 'operations',
                'name' => 'Relieving Letter operations detailed',
                'zoho_template_id' => config('zoho.sign.templates.relieving.operations'),
                'field_map' => [
                    'full_name' => 'Full name',
                    'employee_id' => 'Employee ID',
                    'job_title' => 'Job Title',
                    'join_date' => 'Join date',
                    'last_date' => 'End Date',
                ],
                'date_fields' => ['join_date', 'last_date'],
            ],

            // ----------------------------------------------- experience letters
            [
                'type' => DocumentTemplate::TYPE_EXPERIENCE,
                'department' => 'tech',
                'name' => 'Experience Letter Tech',
                'zoho_template_id' => config('zoho.sign.templates.experience.tech'),
                'field_map' => $experienceFields,
                'date_fields' => ['joining_date', 'leaving_date'],
                'signature_fields' => ['signature'],
            ],
            [
                'type' => DocumentTemplate::TYPE_EXPERIENCE,
                'department' => 'operations',
                'name' => 'Experience Letter - Operations',
                'zoho_template_id' => config('zoho.sign.templates.experience.operations'),
                // Confirmed identical to the tech experience letter, unlike the
                // relieving pair which differ.
                'field_map' => $experienceFields,
                'date_fields' => ['joining_date', 'leaving_date'],
                'signature_fields' => ['signature'],
            ],
        ];
    }
}
