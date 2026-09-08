<?php

namespace App\Services\Zoho;

use App\Models\DocumentTemplate;
use App\Models\Employee;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Turns an employee record into the field payload a specific Zoho Sign
 * template expects, for any letter type.
 *
 * Templates do not share a field vocabulary. The relieving letters call the
 * position "Job Title" while the experience letters call it "Role", and the
 * date labels differ again, so every mapping is resolved per template rather
 * than assumed from the letter type.
 */
class LetterService
{
    /**
     * Logical keys that carry the same underlying value under different names,
     * because templates label them inconsistently. Used so a value is only
     * reported as unmapped when none of its aliases appear on the template.
     */
    private const ALIAS_GROUPS = [
        ['job_title', 'role'],
        ['join_date', 'joining_date'],
        ['last_date', 'leaving_date'],
    ];

    public function __construct(private readonly ZohoSignClient $client) {}

    /**
     * Every logical value a letter template can draw on.
     *
     * @param  array<string, string|null>  $context  sender-supplied values, e.g. hr_name
     * @return array<string, string|null>
     */
    public function logicalValues(Employee $employee, array $context = []): array
    {
        return [
            'full_name' => $employee->full_name,
            'employee_id' => $employee->employee_code,
            // The same value, under the label each template happens to use.
            'job_title' => $employee->position,
            'role' => $employee->position,
            'report_to' => $employee->manager?->full_name,
            // Same two dates, under whichever label the template uses.
            'join_date' => $employee->date_of_joining?->format('d/m/Y'),
            'joining_date' => $employee->date_of_joining?->format('d/m/Y'),
            'last_date' => $employee->date_of_exit?->format('d/m/Y'),
            'leaving_date' => $employee->date_of_exit?->format('d/m/Y'),
            'hr_name' => $context['hr_name'] ?? $this->defaultHrName(),
        ];
    }

    /** Who signs the letter off. The sender, not anything on the employee. */
    private function defaultHrName(): ?string
    {
        return Auth::user()?->name ?? config('zoho.sign.hr_name');
    }

    /**
     * Builds the payload for one template.
     *
     * @return array{
     *     template: DocumentTemplate,
     *     text: array<string, string>,
     *     dates: array<string, string>,
     *     signatures: array<int, string>,
     *     missing: array<int, string>,
     *     unmapped: array<int, string>
     * }
     */
    public function buildPayload(Employee $employee, DocumentTemplate $template, array $context = []): array
    {
        $values = $this->logicalValues($employee, $context);
        $dateKeys = $template->date_fields ?? [];
        $signatureKeys = $template->signature_fields ?? [];

        $text = [];
        $dates = [];
        $signatures = [];
        $missing = [];

        foreach ($template->field_map ?? [] as $logicalKey => $zohoLabel) {
            $mode = $template->fillModeFor($logicalKey, $zohoLabel);

            // Zoho owns Name, Date and Signature fields: it fills them from the
            // recipient or at signing. Sending a value would be wrong, and
            // treating them as missing data would be wronger still.
            if ($mode === 'auto') {
                $signatures[] = $zohoLabel;

                continue;
            }

            $value = $values[$logicalKey] ?? null;

            if (blank($value)) {
                // Every field on these templates is required in Zoho, so a blank
                // one fails at submission rather than sending a partial letter.
                $missing[] = $logicalKey;

                continue;
            }

            if ($mode === 'date') {
                $dates[$zohoLabel] = $value;

                continue;
            }

            $text[$zohoLabel] = $value;
        }

        return [
            'template' => $template,
            'text' => $text,
            'dates' => $dates,
            'signatures' => $signatures,
            'missing' => $missing,
            'unmapped' => $this->unmappedValues($values, $template->logicalKeys()),
        ];
    }

    /**
     * Values we hold that this template has no field for, collapsing aliases so
     * "role" is not reported missing when the template maps "job_title".
     *
     * @param  array<string, string|null>  $values
     * @param  array<int, string>  $mappedKeys
     * @return array<int, string>
     */
    private function unmappedValues(array $values, array $mappedKeys): array
    {
        $held = array_keys(array_filter($values, fn ($value) => filled($value)));
        $unmapped = array_diff($held, $mappedKeys);

        foreach (self::ALIAS_GROUPS as $group) {
            // If any alias in the group is mapped, the value is covered.
            if (array_intersect($group, $mappedKeys) !== []) {
                $unmapped = array_diff($unmapped, $group);
            }
        }

        return array_values($unmapped);
    }

    /** Resolves the right template for this person's department and letter type. */
    public function templateFor(
        Employee $employee,
        string $type = DocumentTemplate::TYPE_RELIEVING,
    ): DocumentTemplate {
        $template = DocumentTemplate::resolve($type, $employee->department_id);

        if ($template === null) {
            throw new RuntimeException(
                'No '.$type.' template is registered for '
                .($employee->department?->name ?? 'this department').'.',
            );
        }

        return $template;
    }

    /**
     * Both exit letters, in the order HR sends them.
     *
     * @return array<int, DocumentTemplate>
     */
    public function exitTemplatesFor(Employee $employee): array
    {
        $templates = [];

        foreach ([DocumentTemplate::TYPE_RELIEVING, DocumentTemplate::TYPE_EXPERIENCE] as $type) {
            $template = DocumentTemplate::resolve($type, $employee->department_id);

            if ($template !== null) {
                $templates[] = $template;
            }
        }

        return $templates;
    }

    /**
     * Sends one letter. Refuses an incomplete payload, because Zoho marks these
     * fields required and a partial send fails after the document already exists.
     */
    public function send(
        Employee $employee,
        string $type = DocumentTemplate::TYPE_RELIEVING,
        bool $quickSend = false,
        array $context = [],
    ): array {
        $template = $this->templateFor($employee, $type);

        return $this->sendTemplate($employee, $template, $quickSend, $context);
    }

    public function sendTemplate(
        Employee $employee,
        DocumentTemplate $template,
        bool $quickSend = false,
        array $context = [],
    ): array {
        if (! $template->isReady()) {
            throw new RuntimeException(
                'Template "'.$template->name.'" has no Zoho template id or field map yet.',
            );
        }

        if (! $template->isVerified()) {
            throw new RuntimeException(
                'Template "'.$template->name.'" has not been verified against the Zoho API. '
                .'Run: php artisan hr:zoho-sign verify --template='.$template->zoho_template_id,
            );
        }

        $payload = $this->buildPayload($employee, $template, $context);

        if ($payload['missing'] !== []) {
            throw new RuntimeException(
                'Cannot send: missing '.implode(', ', $payload['missing']).' for '.$employee->full_name.'.',
            );
        }

        $recipientEmail = $employee->personal_email ?: $employee->work_email;

        if (blank($recipientEmail)) {
            throw new RuntimeException('No email address on record for '.$employee->full_name.'.');
        }

        return $this->client->createDocumentFromTemplate(
            templateId: $template->zoho_template_id,
            textFields: $payload['text'],
            dateFields: $payload['dates'],
            recipients: $this->recipientsFor($template, $employee, $recipientEmail),
            send: $quickSend,
        );
    }

    /**
     * Fills the template's own recipient slots with this employee.
     *
     * Zoho rejects a document whose actions do not carry the template's
     * `action_id`, so the slots are read from the template rather than
     * constructed. Only the first signer slot is filled; any further slots
     * (a countersigner, for instance) are left for a human to complete.
     *
     * @return array<int, array<string, mixed>>
     */
    private function recipientsFor(
        DocumentTemplate $template,
        Employee $employee,
        string $recipientEmail,
    ): array {
        $actions = $this->client->templateActions($template->zoho_template_id);

        if ($actions === []) {
            throw new RuntimeException(
                'Template "'.$template->name.'" defines no recipients in Zoho Sign.',
            );
        }

        $recipients = [];
        $filled = false;

        foreach ($actions as $action) {
            $isSigner = ($action['action_type'] ?? 'SIGN') === 'SIGN';

            $recipients[] = [
                'action_id' => $action['action_id'] ?? null,
                'action_type' => $action['action_type'] ?? 'SIGN',
                'name' => ! $filled && $isSigner
                    ? $employee->full_name
                    : ($action['recipient_name'] ?: $employee->full_name),
                'email' => ! $filled && $isSigner
                    ? $recipientEmail
                    : ($action['recipient_email'] ?: $recipientEmail),
            ];

            if ($isSigner) {
                $filled = true;
            }
        }

        return $recipients;
    }
}
