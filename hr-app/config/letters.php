<?php

return [

    /*
    |---------------------------------------------------------------------------
    | Letterhead
    |---------------------------------------------------------------------------
    |
    | Path, relative to storage/app, of the PDF whose first page is stamped
    | behind every page of a generated letter. Excluded from git: company
    | artwork does not belong in a public repository.
    |
    */

    'letterhead' => env('LETTER_LETTERHEAD', 'letter-templates/letterhead.pdf'),

    /*
    |---------------------------------------------------------------------------
    | Contact address printed in the body
    |---------------------------------------------------------------------------
    |
    | Deliberately NOT defaulted to mail.from.address. That falls back to
    | Laravel's stock "hello@example.com", which silently reached a real
    | relieving letter during development. A letter that tells a departing
    | employee to contact a placeholder address is worse than one that fails
    | to build, so this is its own setting with a real default.
    |
    */

    'hr_email' => env('LETTER_HR_EMAIL', 'hr@coachfoundation.com'),

    /*
    |---------------------------------------------------------------------------
    | Signatory
    |---------------------------------------------------------------------------
    |
    | Name above the signature rule. Falls back to the signed-in user at call
    | time, so this only matters for CLI and queued generation.
    |
    */

    'hr_name' => env('HR_SIGNATORY_NAME', 'HR Department'),

];
