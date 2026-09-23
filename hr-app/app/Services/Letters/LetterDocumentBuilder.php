<?php

namespace App\Services\Letters;

use setasign\Fpdi\Fpdi;

/**
 * Composes a letter PDF on the Coach Foundation letterhead.
 *
 * Builder: blocks are appended in order, each advancing the cursor, and the
 * letterhead is re-stamped on every page the flow creates.
 *
 * This exists because the Zoho template artwork could not be filled correctly
 * — it is missing fields entirely (the tech relieving letter has no name field
 * anywhere) and has one employee's name baked into the body text. Composing
 * the whole page instead of stamping values into someone else's layout means
 * every value sits in the same font and on the same baseline as its sentence.
 */
class LetterDocumentBuilder extends Fpdi
{
    /** Brand colours sampled from the letterhead artwork. */
    private const BLUE = [25, 84, 158];

    private const NAVY = [30, 43, 72];

    private const GREY = [110, 116, 130];

    private const LEFT = 72.0;

    private const RIGHT = 72.0;

    /** Clears the letterhead logo, which ends at roughly 85pt. */
    private const TOP = 132.0;

    private const BOTTOM = 72.0;

    private const BODY_SIZE = 10.5;

    private const LINE = 14.5;

    /** Space after a paragraph. */
    private const PARA_GAP = 9.0;

    private ?string $letterheadTemplate = null;

    private string $letterheadPath;

    public function __construct(string $letterheadPath)
    {
        parent::__construct('P', 'pt', 'Letter');

        $this->letterheadPath = $letterheadPath;

        $this->SetMargins(self::LEFT, self::TOP, self::RIGHT);
        $this->SetAutoPageBreak(true, self::BOTTOM);
        $this->SetTitle('Coach LLC letter');
        $this->setSourceFile($letterheadPath);
        $this->letterheadTemplate = $this->importPage(1);
    }

    /** Width available for text. */
    private function contentWidth(): float
    {
        return $this->GetPageWidth() - self::LEFT - self::RIGHT;
    }

    /**
     * Re-stamps the letterhead behind every page, including ones the text flow
     * creates on its own.
     */
    public function Header(): void // phpcs:ignore
    {
        if ($this->letterheadTemplate === null) {
            return;
        }

        $this->useTemplate($this->letterheadTemplate, 0, 0, $this->GetPageWidth());
        $this->SetY(self::TOP);
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     */
    public function compose(array $blocks): string
    {
        $this->AddPage();

        foreach ($blocks as $block) {
            match ($block['type']) {
                'title' => $this->title($block['text']),
                'meta' => $this->meta($block['rows']),
                'p' => $this->paragraph($block['text']),
                'strong' => $this->strong($block['text']),
                'small' => $this->small($block['text']),
                'bullets' => $this->bullets($block['items']),
                'rule' => $this->rule((float) $block['w']),
                'gap' => $this->Ln((float) $block['h']),
                default => null,
            };
        }

        return $this->Output('S');
    }

    private function title(string $text): void
    {
        $this->SetFont('Helvetica', 'B', 16);
        $this->SetTextColor(...self::BLUE);
        $this->Cell($this->contentWidth(), 22, $this->latin($text), 0, 1, 'C');
        $this->Ln(14);
    }

    /**
     * The label/value rows at the head of the letter. Labels are bold, values
     * sit on the same baseline in the body font.
     *
     * @param  array<string, string>  $rows
     */
    private function meta(array $rows): void
    {
        foreach ($rows as $label => $value) {
            $this->SetFont('Helvetica', 'B', self::BODY_SIZE);
            $this->SetTextColor(...self::NAVY);

            $label = $this->latin($label.': ');
            $width = $this->GetStringWidth($label);

            $this->Cell($width, self::LINE, $label, 0, 0);

            $this->SetFont('Helvetica', '', self::BODY_SIZE);
            $this->Cell($this->contentWidth() - $width, self::LINE, $this->latin($value), 0, 1);
        }

        $this->Ln(self::PARA_GAP);
    }

    private function paragraph(string $text): void
    {
        $this->SetFont('Helvetica', '', self::BODY_SIZE);
        $this->SetTextColor(...self::NAVY);

        $this->avoidWidow($this->latin($text));

        $this->MultiCell($this->contentWidth(), self::LINE, $this->latin($text), 0, 'L');
        $this->Ln(self::PARA_GAP);
    }

    /**
     * Pushes a whole paragraph to the next page when the automatic break would
     * strand only a line or two of it there.
     *
     * Without this the closing paragraph left the words "Coach LLC." alone at
     * the top of page two, which reads as a broken document even though every
     * value on it was correct.
     *
     * Call with text already encoded, so the widths measured here match the
     * widths MultiCell will use.
     */
    private function avoidWidow(string $encoded, int $minOrphanLines = 2): void
    {
        $lines = $this->lineCount($encoded);

        if ($lines <= $minOrphanLines) {
            // Short paragraph: let the normal break handle it, but keep it whole.
            $needed = $lines * self::LINE;
        } else {
            $needed = 0.0;
        }

        $limit = $this->GetPageHeight() - self::BOTTOM;
        $remaining = $limit - $this->GetY();

        if ($lines > $minOrphanLines) {
            // How much of this paragraph would land on the current page?
            $fits = (int) floor($remaining / self::LINE);

            if ($fits > 0 && $fits < $lines && ($lines - $fits) < $minOrphanLines) {
                $this->AddPage();
            }

            return;
        }

        if ($needed > $remaining) {
            $this->AddPage();
        }
    }

    /**
     * Lines a string will occupy in MultiCell at the current font, by the same
     * word-wrapping rule FPDF applies.
     */
    private function lineCount(string $encoded): int
    {
        // MultiCell wraps against the width MINUS its own cell padding, so
        // measuring against the full column width undercounts the lines and
        // the widow check never fires.
        $width = $this->contentWidth() - 2 * $this->cMargin;
        $lines = 0;

        foreach (explode("\n", $encoded) as $block) {
            $current = '';
            $count = 1;

            foreach (explode(' ', $block) as $word) {
                $candidate = $current === '' ? $word : $current.' '.$word;

                if ($this->GetStringWidth($candidate) > $width && $current !== '') {
                    $count++;
                    $current = $word;

                    continue;
                }

                $current = $candidate;
            }

            $lines += $count;
        }

        return $lines;
    }

    private function strong(string $text): void
    {
        $this->SetFont('Helvetica', 'B', self::BODY_SIZE);
        $this->SetTextColor(...self::NAVY);
        $this->MultiCell($this->contentWidth(), self::LINE, $this->latin($text), 0, 'L');
    }

    private function small(string $text): void
    {
        $this->SetFont('Helvetica', '', 9);
        $this->SetTextColor(...self::GREY);
        $this->MultiCell($this->contentWidth(), 12.5, $this->latin($text), 0, 'L');
    }

    /**
     * @param  list<string>  $items
     */
    private function bullets(array $items): void
    {
        $indent = 16.0;

        foreach ($items as $item) {
            $this->SetFont('Helvetica', '', self::BODY_SIZE);
            $this->SetTextColor(...self::BLUE);
            $this->Cell($indent, self::LINE, $this->latin('•'), 0, 0);

            $this->SetTextColor(...self::NAVY);
            $x = self::LEFT + $indent;
            $this->SetX($x);
            $this->MultiCell($this->contentWidth() - $indent, self::LINE, $this->latin($item), 0, 'L');
            $this->Ln(3);
        }

        $this->Ln(self::PARA_GAP - 3);
    }

    /** A signature rule, followed by whatever label comes next. */
    private function rule(float $width): void
    {
        $y = $this->GetY();
        $this->SetDrawColor(...self::GREY);
        $this->SetLineWidth(0.6);
        $this->Line(self::LEFT, $y, self::LEFT + $width, $y);
        $this->Ln(4);
    }

    /**
     * FPDF core fonts are Latin-1 only, so anything outside it has to be
     * folded down rather than emitted as broken bytes.
     */
    private function latin(string $text): string
    {
        // Note: the bullet is NOT pre-substituted here. mb_convert_encoding
        // maps U+2022 to 0x95 by itself, whereas replacing it with chr(149)
        // first feeds invalid UTF-8 into the conversion and yields "?".
        $map = [
            '—' => '-',
            '–' => '-',
            '’' => "'",
            '‘' => "'",
            '“' => '"',
            '”' => '"',
            '…' => '...',
        ];

        $text = strtr($text, $map);

        return mb_convert_encoding($text, 'Windows-1252', 'UTF-8');
    }
}
