<?php

namespace App\Console\Commands;

use App\Console\Concerns\WritesEnvFile;
use App\Services\Google\GmailTokenProvider;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Completes the one-time Google consent and verifies the result.
 *
 * The refresh token is written straight into .env and never printed, so it
 * cannot end up in a terminal transcript, a screen share or a chat log.
 */
class GmailAuthCommand extends Command
{
    use WritesEnvFile;

    protected $signature = 'hr:gmail-auth
        {action=check : url, exchange, check, or test}
        {--code= : The code Google put in the redirect URL, for exchange}
        {--to= : Recipient for test}';

    protected $description = 'Authorise the HR mailbox for sending, and verify it';

    public function handle(GmailTokenProvider $tokens): int
    {
        return match ($this->argument('action')) {
            'url' => $this->url($tokens),
            'exchange' => $this->exchange($tokens),
            'check' => $this->check($tokens),
            'test' => $this->test(),
            default => $this->usage(),
        };
    }

    private function usage(): int
    {
        $this->error('Action must be one of: url, exchange, check, test');

        return self::FAILURE;
    }

    private function url(GmailTokenProvider $tokens): int
    {
        $this->newLine();
        $this->line('1. Open this URL while signed in as the mailbox that will send the letters:');
        $this->newLine();
        $this->line($tokens->consentUrl());
        $this->newLine();
        $this->line('2. Approve the request. The browser will fail to load localhost — that is expected.');
        $this->line('3. Copy the "code" parameter out of the address bar and run:');
        $this->newLine();
        $this->line('   php artisan hr:gmail-auth exchange --code=<code>');
        $this->newLine();
        $this->warn('The code is single use and expires within minutes.');

        return self::SUCCESS;
    }

    private function exchange(GmailTokenProvider $tokens): int
    {
        $code = (string) $this->option('code');

        if ($code === '') {
            $this->error('Pass the authorisation code: --code=<code>');

            return self::FAILURE;
        }

        // Google percent-encodes the code in the redirect URL; pasting it
        // verbatim would otherwise fail with invalid_grant.
        $code = urldecode($code);

        $result = $tokens->exchangeCode($code);

        if (! $result['ok']) {
            $this->error('Exchange failed: '.$result['detail']);

            return self::FAILURE;
        }

        if (! $this->writeEnv('GOOGLE_REFRESH_TOKEN', $result['refresh_token'])) {
            $this->error('Got a refresh token but could not write .env. Add GOOGLE_REFRESH_TOKEN by hand.');

            return self::FAILURE;
        }

        $this->info('Refresh token saved to .env (not printed). A backup of .env was kept.');
        $this->line('Run "php artisan config:clear", then "php artisan hr:gmail-auth check".');

        return self::SUCCESS;
    }

    private function check(GmailTokenProvider $tokens): int
    {
        $result = $tokens->check();

        if (! $result['ok']) {
            $this->error($result['detail']);

            return self::FAILURE;
        }

        $this->info($result['detail']);
        $this->line('Scope: '.GmailTokenProvider::SCOPE.' (send only — cannot read the mailbox)');

        return self::SUCCESS;
    }

    private function test(): int
    {
        $to = (string) $this->option('to');

        if ($to === '') {
            $this->error('Pass a recipient: --to=someone@example.com');

            return self::FAILURE;
        }

        if (config('mail.default') !== 'gmail') {
            $this->warn('MAIL_MAILER is "'.config('mail.default').'", not "gmail".');
            $this->warn('Sending through that mailer instead — set MAIL_MAILER=gmail to test the real path.');
        }

        $from = config('mail.from.address');

        $this->line('Sending a plain test message to '.$to.' as '.$from.' ...');

        try {
            Mail::raw(
                "This is a test from the Coach Foundation HR app.\n\n"
                .'If you received it, letter delivery is working.',
                fn ($message) => $message->to($to)->subject('HR app test message'),
            );
        } catch (\Throwable $e) {
            $this->error('Send failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Accepted by Gmail. Check the inbox, and the Sent folder of '.$from.'.');

        return self::SUCCESS;
    }
}
