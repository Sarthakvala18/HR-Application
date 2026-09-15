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
                    'pdf_path' => $this->artwork()[$template['name']]['pdf_path'] ?? null,
                    'field_positions' => $this->artwork()[$template['name']]['field_positions'] ?? null,
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

    /**
     * The blank letter PDF and the field geometry for each template,
     * captured from Zoho on 2026-09-08 with `templateFieldLayout()`.
     *
     * Coordinates are in points from the top-left of the page. A label can
     * appear more than once, so each holds a list of placements.
     */
    private function artwork(): array
    {
        return [
            'Relieving Letter tech detailed' => [
                'pdf_path' => 'letter-templates/relieving-tech.pdf',
                'field_positions' => [
                    'Sign date' => [
                        [
                            'type' => 'Date',
                            'page' => 0,
                            'x' => 99,
                            'y' => 149,
                            'width' => 98,
                            'height' => 17,
                        ],
                        [
                            'type' => 'Date',
                            'page' => 1,
                            'x' => 102,
                            'y' => 320,
                            'width' => 98,
                            'height' => 17,
                        ],
                    ],
                    'Employee ID' => [
                        [
                            'type' => 'Textfield',
                            'page' => 0,
                            'x' => 138,
                            'y' => 205,
                            'width' => 98,
                            'height' => 17,
                        ],
                    ],
                    'Job Title' => [
                        [
                            'type' => 'Textfield',
                            'page' => 0,
                            'x' => 149,
                            'y' => 318,
                            'width' => 179,
                            'height' => 17,
                        ],
                    ],
                    'Report to' => [
                        [
                            'type' => 'Textfield',
                            'page' => 0,
                            'x' => 477,
                            'y' => 318,
                            'width' => 98,
                            'height' => 17,
                        ],
                    ],
                    'Join Date' => [
                        [
                            'type' => 'CustomDate',
                            'page' => 0,
                            'x' => 106,
                            'y' => 331,
                            'width' => 56,
                            'height' => 11,
                        ],
                    ],
                    'Last Date' => [
                        [
                            'type' => 'CustomDate',
                            'page' => 0,
                            'x' => 186,
                            'y' => 331,
                            'width' => 56,
                            'height' => 11,
                        ],
                    ],
                ],
            ],
            'Relieving Letter operations detailed' => [
                'pdf_path' => 'letter-templates/relieving-operations.pdf',
                'field_positions' => [
                    'Sign date' => [
                        [
                            'type' => 'Date',
                            'page' => 0,
                            'x' => 99,
                            'y' => 130,
                            'width' => 98,
                            'height' => 17,
                        ],
                        [
                            'type' => 'Date',
                            'page' => 1,
                            'x' => 98,
                            'y' => 311,
                            'width' => 98,
                            'height' => 17,
                        ],
                    ],
                    'Full name' => [
                        [
                            'type' => 'Name',
                            'page' => 0,
                            'x' => 88,
                            'y' => 148,
                            'width' => 218,
                            'height' => 17,
                        ],
                        [
                            'type' => 'Name',
                            'page' => 0,
                            'x' => 251,
                            'y' => 299,
                            'width' => 117,
                            'height' => 17,
                        ],
                        [
                            'type' => 'Name',
                            'page' => 0,
                            'x' => 119,
                            'y' => 633,
                            'width' => 96,
                            'height' => 17,
                        ],
                    ],
                    'Employee ID' => [
                        [
                            'type' => 'Textfield',
                            'page' => 0,
                            'x' => 139,
                            'y' => 185,
                            'width' => 291,
                            'height' => 17,
                        ],
                    ],
                    'Job Title' => [
                        [
                            'type' => 'Textfield',
                            'page' => 0,
                            'x' => 79,
                            'y' => 312,
                            'width' => 162,
                            'height' => 15,
                        ],
                    ],
                    'Join date' => [
                        [
                            'type' => 'CustomDate',
                            'page' => 0,
                            'x' => 102,
                            'y' => 327,
                            'width' => 64,
                            'height' => 11,
                        ],
                    ],
                    'End Date' => [
                        [
                            'type' => 'CustomDate',
                            'page' => 0,
                            'x' => 188,
                            'y' => 326,
                            'width' => 73,
                            'height' => 11,
                        ],
                    ],
                ],
            ],
            'Experience Letter Tech' => [
                'pdf_path' => 'letter-templates/experience-tech.pdf',
                'field_positions' => [
                    'Full name' => [
                        [
                            'type' => 'Name',
                            'page' => 0,
                            'x' => 181,
                            'y' => 184,
                            'width' => 91,
                            'height' => 17,
                        ],
                        [
                            'type' => 'Name',
                            'page' => 0,
                            'x' => 172,
                            'y' => 226,
                            'width' => 91,
                            'height' => 17,
                        ],
                    ],
                    'Role' => [
                        [
                            'type' => 'Textfield',
                            'page' => 0,
                            'x' => 422,
                            'y' => 184,
                            'width' => 98,
                            'height' => 17,
                        ],
                    ],
                    'Joining date' => [
                        [
                            'type' => 'CustomDate',
                            'page' => 0,
                            'x' => 186,
                            'y' => 199,
                            'width' => 98,
                            'height' => 17,
                        ],
                    ],
                    'Leaving date' => [
                        [
                            'type' => 'CustomDate',
                            'page' => 0,
                            'x' => 300,
                            'y' => 199,
                            'width' => 98,
                            'height' => 17,
                        ],
                    ],
                    'HR Name' => [
                        [
                            'type' => 'Textfield',
                            'page' => 0,
                            'x' => 72,
                            'y' => 467,
                            'width' => 98,
                            'height' => 17,
                        ],
                    ],
                    'Signature' => [
                        [
                            'type' => 'Signature',
                            'page' => 0,
                            'x' => 185,
                            'y' => 618,
                            'width' => 225,
                            'height' => 39,
                        ],
                    ],
                ],
            ],
            'Experience Letter - Operations' => [
                'pdf_path' => 'letter-templates/experience-operations.pdf',
                'field_positions' => [
                    'Full name' => [
                        [
                            'type' => 'Name',
                            'page' => 0,
                            'x' => 172,
                            'y' => 186,
                            'width' => 91,
                            'height' => 17,
                        ],
                        [
                            'type' => 'Name',
                            'page' => 0,
                            'x' => 164,
                            'y' => 224,
                            'width' => 91,
                            'height' => 14,
                        ],
                        [
                            'type' => 'Name',
                            'page' => 0,
                            'x' => 122,
                            'y' => 299,
                            'width' => 88,
                            'height' => 14,
                        ],
                        [
                            'type' => 'Name',
                            'page' => 0,
                            'x' => 268,
                            'y' => 336,
                            'width' => 88,
                            'height' => 14,
                        ],
                    ],
                    'Role' => [
                        [
                            'type' => 'Textfield',
                            'page' => 0,
                            'x' => 420,
                            'y' => 188,
                            'width' => 94,
                            'height' => 15,
                        ],
                    ],
                    'Joining date' => [
                        [
                            'type' => 'CustomDate',
                            'page' => 0,
                            'x' => 201,
                            'y' => 203,
                            'width' => 83,
                            'height' => 15,
                        ],
                    ],
                    'Leaving date' => [
                        [
                            'type' => 'CustomDate',
                            'page' => 0,
                            'x' => 298,
                            'y' => 202,
                            'width' => 93,
                            'height' => 15,
                        ],
                    ],
                    'HR Name' => [
                        [
                            'type' => 'Textfield',
                            'page' => 0,
                            'x' => 72,
                            'y' => 435,
                            'width' => 98,
                            'height' => 17,
                        ],
                    ],
                    'Signature' => [
                        [
                            'type' => 'Signature',
                            'page' => 0,
                            'x' => 183,
                            'y' => 499,
                            'width' => 225,
                            'height' => 39,
                        ],
                    ],
                ],
            ],
        ];
    }
}
