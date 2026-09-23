<?php

namespace Tests\Concerns;

use setasign\Fpdi\Fpdi;

/**
 * Gives the letter tests a letterhead of their own.
 *
 * The real artwork is excluded from git, so a test that relied on it would pass
 * here and fail on a fresh clone or in CI. This generates a stand-in and points
 * config at it, keeping the suite self-contained.
 */
trait MakesLetterhead
{
    protected function fakeLetterhead(): string
    {
        $dir = storage_path('app/letter-templates');

        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $path = $dir.'/test-letterhead.pdf';

        if (! is_file($path)) {
            $pdf = new Fpdi('P', 'pt', 'Letter');
            $pdf->AddPage();
            $pdf->SetFont('Helvetica', 'B', 14);
            $pdf->Text(72, 60, 'TEST LETTERHEAD');

            file_put_contents($path, $pdf->Output('S'));
        }

        config(['letters.letterhead' => 'letter-templates/test-letterhead.pdf']);

        return $path;
    }
}
