<?php

return [
    /*
     | Zoho data centre. Determines both the accounts host used for token
     | refresh and the Sign API host. Zoho tokens are region-locked, so a token
     | minted in .com will not work against .in or .eu.
     */
    'dc' => env('ZOHO_DC', 'com'),

    'client_id' => env('ZOHO_CLIENT_ID'),
    'client_secret' => env('ZOHO_CLIENT_SECRET'),
    'refresh_token' => env('ZOHO_REFRESH_TOKEN'),

    /*
     | Last-resort access token for local poking. Zoho access tokens live one
     | hour, so this is never viable for a real run: the refresh token above is
     | what the app actually needs.
     */
    'access_token_override' => env('ZOHO_SIGN_ACCESS_TOKEN_TEMP'),

    'sign' => [
        'timeout' => (int) env('ZOHO_SIGN_TIMEOUT', 30),

        /*
         | Name printed on letters that carry an "HR Name" field. Falls back to
         | the signed-in user, so this only matters for CLI and queued sends.
         */
        'hr_name' => env('HR_SIGNATORY_NAME'),

        /*
         | Zoho Sign template ids, per letter type and department. Kept in the
         | environment rather than in the seeder so the repository carries no
         | account-specific identifiers.
         */
        'templates' => [
            'relieving' => [
                'tech' => env('ZOHO_TPL_RELIEVING_TECH'),
                'operations' => env('ZOHO_TPL_RELIEVING_OPS'),
            ],
            'experience' => [
                'tech' => env('ZOHO_TPL_EXPERIENCE_TECH'),
                'operations' => env('ZOHO_TPL_EXPERIENCE_OPS'),
            ],
        ],
    ],
];
