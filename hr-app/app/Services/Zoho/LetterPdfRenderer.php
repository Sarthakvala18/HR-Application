<?php

namespace App\Services\Zoho;

use App\Models\DocumentTemplate;
use App\Models\Employee;
use RuntimeException;
use setasign\Fpdi\Fpdi;

/**
 * Fills a letter by stamping values onto the blank template PDF.
 *
 * The Zoho Sign licence allows creating documents but not sending them, so the
 * app renders the letter itself using the same artwork and the same field
 * coordinates Zoho holds. Output is visually the letter Zoho would produce,
 * minus the signature block.
 */
class LetterPdfRenderer
{
    /**
     * Zoho records a field's top-left corner; text sits on a baseline roughly
     * three-quarters down the box. Deriving it from the field height keeps
     * short fields aligned as well as tall ones.
     */
    private const BASELINE_RATIO = 0.72;

    /**
     * Field types Zoho would populate itself during signing. Rendering locally
     * means Zoho is not involved, so a Name field has to be filled here or the
     * letter goes out without the employee's name in it.
     */
    private const SELF_FILLED_TYPES = ['Textfield', 'CustomDate', 'Name'];

    private const FONT = 'Helvetica';

    private const FONT_SIZE = 10;

    public function __construct(private readonly LetterService $letters) {}

    /**
     * Renders the letter and returns the raw PDF bytes.
     *
     * @param  array<string, string>  $context  values supplied for gaps
     */
    public function render(Employee $employee, DocumentTemplate $template, array $context = []): string
    {
        $source = $this->templatePath($template);

        $payload = $this->letters->buildPayload($employee, $template, $context);

        if ($payload['missing'] !== []) {
            throw new RuntimeException(
                'Cannot render: missing '.implode(', ', $payload['missing']).' for '.$employee->full_name.'.',
            );
        }

        $positions = $template->field_positions ?? [];

        if ($positions === []) {
            throw new RuntimeException(
                'Template "'.$template->name.'" has no field positions. Run: php artisan hr:zoho-sign layout',
            );
        }

        $values = $this->valuesToStamp($employee, $template, $payload, $context);

        // Points, not FPDF's default millimetres: Zoho records field
        // coordinates in points, and a unit mismatch silently places every
        // value off the page rather than erroring.
        $pdf = new Fpdi('P', 'pt');
        $pdf->SetAutoPageBreak(false);
        $pageCount = $pdf->setSourceFile($source);

        for ($page = 1; $page <= $pageCount; $page++) {
            $templateId = $pdf->importPage($page);
            $size = $pdf->getTemplateSize($templateId);

            $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
            $pdf->useTemplate($templateId);

            $pdf->SetFont(self::FONT, '', self::FONT_SIZE);
            $pdf->SetTextColor(0, 0, 0);

            foreach ($values as $label => $value) {
                // A label can be placed several times in one letter, so every
                // placement on this page is filled.
                foreach ($positions[$label] ?? [] as $position) {
                    // Zoho pages are zero-indexed, FPDI's are one-indexed.
                    if (((int) $position['page'] + 1) !== $page) {
                        continue;
                    }

                    $baseline = (float) $position['y']
                        + ((float) ($position['height'] ?: 17) * self::BASELINE_RATIO);

                    $pdf->SetXY((float) $position['x'], $baseline);
                    $pdf->Write(0, $this->sanitise((string) $value));
                }
            }
        }

        return $pdf->Output('S');
    }

    /**
     * Every value this renderer stamps, keyed by Zoho field label.
     *
     * Unlike a Zoho send, a locally rendered letter has nobody else to fill
     * anything in, so Name fields are included here. Signature and signing-date
     * fields are deliberately left blank for the employee to complete.
     *
     * @return array<string, string>
     */
    private function valuesToStamp(
        Employee $employee,
        DocumentTemplate $template,
        array $payload,
        array $context,
    ): array {
        $values = $payload['text'] + $payload['dates'];

        $logical = $this->letters->logicalValues($employee, $context);
        $types = $template->field_types ?? [];

        foreach ($template->field_map ?? [] as $logicalKey => $label) {
            if (isset($values[$label])) {
                continue;
            }

            $type = $types[$label] ?? null;

            if ($type !== 'Name' || blank($logical[$logicalKey] ?? null)) {
                continue;
            }

            $values[$label] = (string) $logical[$logicalKey];
        }

        return array_filter(
            $values,
            fn (string $label) => in_array(
                $types[$label] ?? 'Textfield',
                self::SELF_FILLED_TYPES,
                true,
            ),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /** A filename an employee would recognise on an attachment. */
    public function filename(Employee $employee, DocumentTemplate $template): string
    {
        $type = ucfirst($template->type);
        $name = preg_replace('/[^A-Za-z0-9]+/', '-', $employee->full_name) ?? 'employee';

        return trim($type.'-Letter-'.$name, '-').'.pdf';
    }

    private function templatePath(DocumentTemplate $template): string
    {
        if (blank($template->pdf_path)) {
            throw new RuntimeException(
                'Template "'.$template->name.'" has no stored PDF to fill.',
            );
        }

        $path = storage_path('app/'.ltrim($template->pdf_path, '/'));

        if (! is_readable($path)) {
            throw new RuntimeException('Letter PDF not found at '.$path);
        }

        return $path;
    }

    /**
     * FPDF's core fonts are Latin-1 only, so anything outside it would render
     * as mojibake rather than failing loudly.
     */
    private function sanitise(string $value): string
    {
        $converted = @iconv('UTF-8', 'windows-1252//TRANSLIT', $value);

        return $converted === false ? $value : $converted;
    }
}
