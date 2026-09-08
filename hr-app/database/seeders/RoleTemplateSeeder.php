<?php

namespace Database\Seeders;

use App\Models\App;
use App\Models\Department;
use App\Models\RoleTemplate;
use App\Models\RoleTemplateApp;
use Illuminate\Database\Seeder;

/**
 * Starting role templates, drawn from the positions that appear in the existing
 * onboarding form history. These are deliberately conservative: HR edits them
 * in the UI, and every change applies to future hires without a code change.
 *
 * Channel lists and Bitwarden collections are placeholders until HR fills the
 * role-template table (PREP.md section C).
 */
class RoleTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $apps = App::pluck('id', 'key');
        $departments = Department::pluck('id', 'key');

        foreach ($this->templates() as $template) {
            $model = RoleTemplate::updateOrCreate(
                ['key' => $template['key']],
                [
                    'name' => $template['name'],
                    'description' => $template['description'],
                    'department_id' => $departments[$template['department']] ?? null,
                    'responsibilities_md' => $template['responsibilities'] ?? null,
                    'active' => true,
                ],
            );

            $keptAppIds = [];

            // Written through the pivot model rather than sync() so the array
            // and boolean casts apply on write as well as on read.
            foreach ($template['apps'] as $appKey => $config) {
                if (! isset($apps[$appKey])) {
                    continue;
                }

                $appId = $apps[$appKey];
                $keptAppIds[] = $appId;

                RoleTemplateApp::updateOrCreate(
                    ['role_template_id' => $model->id, 'app_id' => $appId],
                    [
                        'license_tier' => $config['tier'] ?? null,
                        'scopes' => $config['scopes'] ?? null,
                        'required' => $config['required'] ?? true,
                    ],
                );
            }

            RoleTemplateApp::where('role_template_id', $model->id)
                ->whereNotIn('app_id', $keptAppIds)
                ->delete();
        }
    }

    private function templates(): array
    {
        // Every staff role gets the identity backbone plus chat.
        $base = [
            'google_workspace' => ['tier' => 'business_standard'],
            'slack' => ['tier' => 'member'],
            'bitwarden' => [],
        ];

        return [
            [
                'key' => 'sales',
                'name' => 'Sales',
                'department' => 'sales',
                'description' => 'Sales and enrolment staff working leads and deals.',
                'responsibilities' => "- Manage assigned leads and opportunities through the CRM pipeline\n- Conduct discovery and enrolment calls\n- Maintain accurate deal records and next steps\n- Report pipeline status in the weekly review",
                'apps' => $base + [
                    'zoho_one' => ['tier' => 'standard'],
                    'zoom' => ['tier' => 'pro'],
                    'aws_s3' => ['required' => false],
                ],
            ],
            [
                'key' => 'client_manager',
                'name' => 'Client Manager / Customer Support',
                'department' => 'customer_support',
                'description' => 'Client-facing delivery and support, works tickets in Desk.',
                'responsibilities' => "- Own a portfolio of client accounts end to end\n- Respond to support tickets within the agreed SLA\n- Run client calls and record them to the correct repository\n- Escalate delivery risks to the department head",
                'apps' => $base + [
                    'zoho_one' => ['tier' => 'standard'],
                    'zoho_desk' => ['scopes' => ['departments' => ['all'], 'ticket_access' => 'all']],
                    'zoom' => ['tier' => 'pro'],
                    'aws_s3' => [],
                ],
            ],
            [
                'key' => 'finance',
                'name' => 'Finance',
                'department' => 'finance',
                'description' => 'Finance and accounts. Has payout data access in this app.',
                'responsibilities' => "- Maintain the books and reconcile payment gateways\n- Process payroll and contractor payouts\n- Produce monthly financial reporting\n- Own vendor and subscription cost tracking",
                'apps' => $base + [
                    'zoho_one' => ['tier' => 'standard'],
                    'zoom' => ['tier' => 'basic'],
                ],
            ],
            [
                'key' => 'tech',
                'name' => 'Tech',
                'department' => 'tech',
                'description' => 'Engineering and automation.',
                'responsibilities' => "- Build and maintain internal applications and automations\n- Support the integration stack across Zoho, Stripe and the automation hub\n- Respond to technical incidents\n- Document systems and access requirements",
                'apps' => $base + [
                    'zoho_one' => ['tier' => 'standard'],
                    'zoho_desk' => ['required' => false],
                    'zoom' => ['tier' => 'basic'],
                    'n8n' => [],
                    'aws_s3' => ['required' => false],
                ],
            ],
            [
                'key' => 'product',
                'name' => 'Product Delivery',
                'department' => 'product',
                'description' => 'Product delivery and deliverable management.',
                'responsibilities' => "- Coordinate delivery of client deliverables to schedule\n- Maintain the delivery tracker and flag slippage early\n- Liaise between clients and delivery contributors\n- Uphold quality standards on outgoing work",
                'apps' => $base + [
                    'zoho_one' => ['tier' => 'standard'],
                    'zoom' => ['tier' => 'basic'],
                ],
            ],
            [
                'key' => 'marketing',
                'name' => 'Marketing',
                'department' => 'marketing',
                'description' => 'Marketing, content and SEO outreach.',
                'responsibilities' => "- Plan and execute campaigns against the content calendar\n- Manage outreach and placement pipelines\n- Track campaign performance and report on it\n- Maintain brand consistency across channels",
                'apps' => $base + [
                    'zoho_one' => ['tier' => 'standard'],
                    'zoom' => ['tier' => 'basic'],
                ],
            ],
            [
                'key' => 'operations',
                'name' => 'Operations / HR',
                'department' => 'operations',
                'description' => 'Ops and people operations. Runs onboarding in this app.',
                'responsibilities' => "- Run onboarding and offboarding end to end\n- Maintain the employee record and access matrix\n- Manage leave, comp-off and holiday communications\n- Keep company documentation current",
                'apps' => $base + [
                    'zoho_one' => ['tier' => 'standard'],
                    'zoho_desk' => ['required' => false],
                    'zoom' => ['tier' => 'pro'],
                    'typeform' => [],
                ],
            ],
            [
                'key' => 'enrolment_coach',
                'name' => 'Enrolment Coach (commission)',
                'department' => 'sales',
                'description' => 'Commission-based closer. Minimal footprint.',
                'responsibilities' => "- Conduct enrolment calls with qualified prospects\n- Record call outcomes in the CRM\n- Meet agreed conversion targets\n- Follow the approved enrolment script and compliance rules",
                'apps' => [
                    'google_workspace' => ['tier' => 'business_starter'],
                    'slack' => ['tier' => 'multi_channel_guest'],
                    'zoom' => ['tier' => 'pro'],
                    'zoho_one' => ['tier' => 'standard', 'required' => false],
                ],
            ],
            [
                'key' => 'freelancer',
                'name' => 'Freelancer / Contractor',
                'department' => 'general',
                'description' => 'Situation-specific access. NDA must be signed before anything is granted.',
                'responsibilities' => "- Deliver the scope agreed in the engagement\n- Maintain confidentiality of all company and client information\n- Use only company-provided systems for project work\n- Return or destroy company data at the end of the engagement",
                'apps' => [
                    'slack' => ['tier' => 'single_channel_guest'],
                    'bitwarden' => ['required' => false],
                    'google_workspace' => ['tier' => 'business_starter', 'required' => false],
                ],
            ],
        ];
    }
}
