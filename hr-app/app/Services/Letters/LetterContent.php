<?php

namespace App\Services\Letters;

use InvalidArgumentException;

/**
 * The copy for each exit letter, as ordered blocks with the values already
 * substituted into the sentences.
 *
 * Kept separate from the PDF builder so the wording is editable without
 * touching layout code, and so a value can never end up in a different font
 * from the sentence around it — the defect that made the stamped Zoho artwork
 * look pasted together.
 */
class LetterContent
{
    public const RELIEVING = 'relieving';

    public const EXPERIENCE = 'experience';

    /**
     * Responsibilities differ per team. An unknown team fails loudly rather
     * than quietly issuing a letter that describes the wrong job.
     *
     * @var array<string, array<string, list<string>>>
     */
    private const RESPONSIBILITIES = [
        'Tech' => [
            self::RELIEVING => [
                'Development, maintenance, and troubleshooting of tracking mechanisms, funnels, and marketing automations',
                'Integration and upkeep of CRM, payment gateway, and analytics systems (e.g., Clickfunnels, Google Tag Manager, Google Analytics 4)',
                'Scripting and automating internal processes to improve efficiency and eliminate manual work',
                'Collaboration with cross functional teams to resolve data discrepancies and technical issues',
            ],
            self::EXPERIENCE => [
                'Development, maintenance, and troubleshooting of internal tools, funnels, and automations',
                'Integration and upkeep of CRM, tracking, and analytics systems',
                'Collaboration with cross-functional teams to resolve technical issues and improve system efficiency',
            ],
        ],
        'Operations' => [
            self::RELIEVING => [
                'Coordination of day-to-day business operations and internal workflows.',
                'Monitoring processes to ensure timely, accurate execution of tasks across departments.',
                'Maintaining operational documentation, SOPs, and reporting for leadership review.',
                'Supporting cross-department initiatives and resolving process bottlenecks.',
            ],
            self::EXPERIENCE => [
                'Coordination of day-to-day business operations and internal processes',
                'Monitoring workflows to ensure timely and efficient execution of tasks',
                'Supporting cross-department projects and maintaining operational documentation',
            ],
        ],
    ];

    /** Teams this class can write a letter for. */
    public static function supportedTeams(): array
    {
        return array_keys(self::RESPONSIBILITIES);
    }

    /**
     * @param  array<string, string>  $v  name, employee_id, address, position, team,
     *                                    report_to, join_date, last_date, letter_date,
     *                                    hr_name, hr_email
     * @return list<array<string, mixed>>
     */
    public function blocks(string $type, array $v): array
    {
        $team = $v['team'] ?? '';

        if (! isset(self::RESPONSIBILITIES[$team])) {
            throw new InvalidArgumentException(
                "No letter copy for the '{$team}' team. Add its responsibilities before issuing a letter."
            );
        }

        if (! isset(self::RESPONSIBILITIES[$team][$type])) {
            throw new InvalidArgumentException("Unknown letter type '{$type}'.");
        }

        $bullets = self::RESPONSIBILITIES[$team][$type];

        return $type === self::RELIEVING
            ? $this->relieving($v, $bullets)
            : $this->experience($v, $bullets);
    }

    public function title(string $type): string
    {
        return match ($type) {
            self::RELIEVING => 'Relieving Letter',
            self::EXPERIENCE => 'Experience Letter',
            default => throw new InvalidArgumentException("Unknown letter type '{$type}'."),
        };
    }

    /**
     * @param  array<string, string>  $v
     * @param  list<string>  $bullets
     * @return list<array<string, mixed>>
     */
    private function relieving(array $v, array $bullets): array
    {
        $intro = 'Coach LLC is a business coaching company founded in 2015 by Sai C N G Blackbyrn, '
            .'building brands, client acquisition systems, and business foundations for coaches around '
            .'the world. As a remote first company operating across multiple countries, we place a high '
            ."value on documenting each team member's journey with us accurately and respectfully.";

        $relieved = 'We hereby confirm that the resignation/separation process has been completed in '
            .'accordance with company policy, and that the employee has been formally relieved of all '
            .'duties and responsibilities effective the last working day stated above.';

        $property = 'The employee has confirmed the return of all company property, including but not '
            .'limited to login credentials, devices, confidential documents, and access to internal '
            .'systems, in accordance with the terms of the agreement signed with Coach LLC.';

        $dues = 'All dues, including final settlement of salary and any outstanding reimbursements, '
            ."have been / will be processed in accordance with Coach LLC's standard payroll cycle. Any "
            .'confidentiality, non disclosure, and non solicitation obligations agreed to during '
            .'employment remain in effect beyond the last working day, as outlined in the '
            ."employee's original agreement.";

        $thanks = "We thank {$v['name']} for their contributions to the {$v['team']} Team and wish them "
            .'continued success in their future endeavors. Please feel free to contact the HR Department '
            ."at {$v['hr_email']} with any questions regarding this letter or the employee's tenure at "
            .'Coach LLC.';

        $confirm = "This letter is to formally confirm that {$v['name']} was employed with Coach LLC as a "
            ."{$v['position']} in the {$v['team']} Team, reporting to {$v['report_to']}, from "
            ."{$v['join_date']} to {$v['last_date']}.";

        return [
            ['type' => 'title', 'text' => 'Relieving Letter'],
            // A blank row is dropped rather than printed as a dangling label:
            // "Employee Address:" with nothing after it is what made the
            // stamped letters look unfinished.
            ['type' => 'meta', 'rows' => array_filter([
                'Date' => $v['letter_date'],
                'Employee Name' => $v['name'],
                'Employee Address' => $v['address'],
                'Employee ID' => $v['employee_id'],
            ], fn (string $value) => $value !== '')],
            ['type' => 'p', 'text' => "Dear {$v['name']},"],
            ['type' => 'p', 'text' => $intro],
            ['type' => 'p', 'text' => $confirm],
            ['type' => 'p', 'text' => "During their tenure with the {$v['team']} Team, key responsibilities included:"],
            ['type' => 'bullets', 'items' => $bullets],
            ['type' => 'p', 'text' => $relieved],
            ['type' => 'p', 'text' => $property],
            ['type' => 'p', 'text' => $dues],
            ['type' => 'p', 'text' => $thanks],
            ['type' => 'gap', 'h' => 10],
            ['type' => 'p', 'text' => 'Sincerely,'],
            ['type' => 'gap', 'h' => 24],
            ['type' => 'rule', 'w' => 200],
            ['type' => 'strong', 'text' => $v['hr_name']],
            ['type' => 'p', 'text' => 'HR Department, Coach LLC'],
            ['type' => 'small', 'text' => $v['hr_email']],
            ['type' => 'gap', 'h' => 24],
            ['type' => 'strong', 'text' => 'Employee Acknowledgement & Signature'],
            ['type' => 'gap', 'h' => 32],
            ['type' => 'rule', 'w' => 260],
            ['type' => 'small', 'text' => $v['name']],
            ['type' => 'gap', 'h' => 16],
            ['type' => 'small', 'text' => 'Date:'],
        ];
    }

    /**
     * @param  array<string, string>  $v
     * @param  list<string>  $bullets
     * @return list<array<string, mixed>>
     */
    private function experience(array $v, array $bullets): array
    {
        $certify = "This is to certify that {$v['name']} worked with Coach LLC as a {$v['position']} "
            ."in the {$v['team']} Team from {$v['join_date']} to {$v['last_date']}.";

        $found = "We found {$v['name']} to be sincere, dedicated, and professional throughout their "
            .'tenure at Coach LLC. We wish them all the best in their future endeavors.';

        return [
            ['type' => 'title', 'text' => 'Experience Letter'],
            ['type' => 'meta', 'rows' => ['Date' => $v['letter_date']]],
            ['type' => 'p', 'text' => 'To Whomsoever It May Concern,'],
            ['type' => 'p', 'text' => $certify],
            ['type' => 'p', 'text' => 'During this tenure, key responsibilities included:'],
            ['type' => 'bullets', 'items' => $bullets],
            ['type' => 'p', 'text' => $found],
            ['type' => 'p', 'text' => "This letter is issued upon the request of {$v['name']} for whatever purpose it may serve."],
            ['type' => 'gap', 'h' => 10],
            ['type' => 'p', 'text' => 'Sincerely,'],
            ['type' => 'gap', 'h' => 24],
            ['type' => 'rule', 'w' => 200],
            ['type' => 'strong', 'text' => $v['hr_name']],
            ['type' => 'p', 'text' => 'HR Department, Coach LLC'],
            ['type' => 'small', 'text' => $v['hr_email']],
            ['type' => 'gap', 'h' => 28],
            ['type' => 'strong', 'text' => 'Employee Signature'],
            ['type' => 'gap', 'h' => 32],
            ['type' => 'rule', 'w' => 260],
            ['type' => 'small', 'text' => $v['name']],
            ['type' => 'gap', 'h' => 16],
            ['type' => 'small', 'text' => 'Date:'],
        ];
    }
}
