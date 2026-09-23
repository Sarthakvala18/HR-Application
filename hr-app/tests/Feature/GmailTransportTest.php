<?php

namespace Tests\Feature;

use App\Mail\Transport\GmailApiTransport;
use App\Services\Google\GmailTokenProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

class GmailTransportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.google.client_id' => 'test-client-id',
            'services.google.client_secret' => 'test-client-secret',
            'services.google.redirect_uri' => 'http://localhost:8140/oauth/google/callback',
            'services.google.refresh_token' => 'test-refresh-token',
            'mail.default' => 'gmail',
            'mail.from.address' => 'hr@coachfoundation.com',
            'mail.from.name' => 'Coach Foundation HR',
        ]);

        Cache::flush();
    }

    private function fakeTokenAndSend(int $sendStatus = 200, array $sendBody = ['id' => 'gmail-msg-1']): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'access-token-1',
                'expires_in' => 3599,
            ]),
            'gmail.googleapis.com/*' => Http::response($sendBody, $sendStatus),
        ]);
    }

    public function test_the_scope_is_send_only(): void
    {
        // Gmail's SMTP needs https://mail.google.com/, which also grants read
        // and delete on the whole mailbox. This app must never ask for it.
        $this->assertSame(
            'https://www.googleapis.com/auth/gmail.send',
            GmailTokenProvider::SCOPE,
        );

        $url = app(GmailTokenProvider::class)->consentUrl();

        $this->assertStringContainsString('gmail.send', urldecode($url));
        $this->assertStringNotContainsString('mail.google.com', urldecode($url));
    }

    public function test_the_consent_url_asks_for_a_refresh_token(): void
    {
        $url = urldecode(app(GmailTokenProvider::class)->consentUrl());

        // Without both of these Google returns an access token only, and the
        // app cannot send again in an hour.
        $this->assertStringContainsString('access_type=offline', $url);
        $this->assertStringContainsString('prompt=consent', $url);
        $this->assertStringContainsString('client_id=test-client-id', $url);
    }

    public function test_it_sends_through_the_gmail_api(): void
    {
        $this->fakeTokenAndSend();

        Mail::raw('Letter body', fn ($m) => $m->to('someone@example.com')->subject('Your letter'));

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'gmail.googleapis.com')) {
                return false;
            }

            $raw = $request->data()['raw'] ?? '';
            $decoded = base64_decode(strtr($raw, '-_', '+/'), true);

            return str_contains($decoded, 'Your letter')
                && str_contains($decoded, 'someone@example.com')
                && str_contains($decoded, 'Letter body');
        });
    }

    public function test_the_payload_is_base64url_with_no_padding(): void
    {
        $this->fakeTokenAndSend();

        Mail::raw('Body', fn ($m) => $m->to('someone@example.com')->subject('Subject'));

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'gmail.googleapis.com')) {
                return true;
            }

            $raw = $request->data()['raw'] ?? '';

            // Gmail rejects the standard alphabet and trailing padding.
            return ! str_contains($raw, '+')
                && ! str_contains($raw, '/')
                && ! str_contains($raw, '=');
        });
    }

    public function test_it_uses_the_access_token_as_a_bearer_credential(): void
    {
        $this->fakeTokenAndSend();

        Mail::raw('Body', fn ($m) => $m->to('someone@example.com')->subject('Subject'));

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'gmail.googleapis.com')) {
                return true;
            }

            return $request->hasHeader('Authorization', 'Bearer access-token-1');
        });
    }

    public function test_it_retries_once_with_a_fresh_token_after_a_401(): void
    {
        $tokenCalls = 0;
        $sendCalls = 0;

        Http::fake([
            'oauth2.googleapis.com/token' => function () use (&$tokenCalls) {
                $tokenCalls++;

                return Http::response(['access_token' => 'access-token-'.$tokenCalls]);
            },
            'gmail.googleapis.com/*' => function () use (&$sendCalls) {
                $sendCalls++;

                return $sendCalls === 1
                    ? Http::response(['error' => ['message' => 'Invalid Credentials']], 401)
                    : Http::response(['id' => 'gmail-msg-2']);
            },
        ]);

        Mail::raw('Body', fn ($m) => $m->to('someone@example.com')->subject('Subject'));

        $this->assertSame(2, $sendCalls, 'The 401 should have been retried exactly once.');
        $this->assertSame(2, $tokenCalls, 'The retry should have minted a fresh token.');
    }

    public function test_a_failed_send_surfaces_the_reason_not_just_a_status(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-token-1']),
            'gmail.googleapis.com/*' => Http::response([
                'error' => [
                    'message' => 'Request had insufficient authentication scopes.',
                    'errors' => [['reason' => 'insufficientPermissions']],
                ],
            ], 403),
        ]);

        try {
            Mail::raw('Body', fn ($m) => $m->to('someone@example.com')->subject('Subject'));
            $this->fail('A 403 from Gmail should not be swallowed.');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('insufficient authentication scopes', $e->getMessage());
            $this->assertStringContainsString('insufficientPermissions', $e->getMessage());
        }
    }

    public function test_the_access_token_is_cached_across_sends(): void
    {
        $tokenCalls = 0;

        Http::fake([
            'oauth2.googleapis.com/token' => function () use (&$tokenCalls) {
                $tokenCalls++;

                return Http::response(['access_token' => 'access-token-1']);
            },
            'gmail.googleapis.com/*' => Http::response(['id' => 'x']),
        ]);

        Mail::raw('One', fn ($m) => $m->to('a@example.com')->subject('One'));
        Mail::raw('Two', fn ($m) => $m->to('b@example.com')->subject('Two'));

        $this->assertSame(1, $tokenCalls, 'Two sends should share one access token.');
    }

    public function test_it_explains_how_to_authorise_when_no_refresh_token_exists(): void
    {
        config(['services.google.refresh_token' => null]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/hr:gmail-auth/');

        app(GmailTokenProvider::class)->accessToken();
    }

    public function test_exchange_reports_when_google_returns_no_refresh_token(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'only-an-access-token']),
        ]);

        $result = app(GmailTokenProvider::class)->exchangeCode('some-code');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('revoke', $result['detail']);
    }

    public function test_exchange_surfaces_googles_oauth_error(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'error' => 'invalid_grant',
                'error_description' => 'Bad Request',
            ], 400),
        ]);

        $result = app(GmailTokenProvider::class)->exchangeCode('stale-code');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('invalid_grant', $result['detail']);
        $this->assertStringContainsString('Bad Request', $result['detail']);
    }

    public function test_check_reports_a_missing_client_id(): void
    {
        config(['services.google.client_id' => null]);

        $result = app(GmailTokenProvider::class)->check();

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('GOOGLE_CLIENT_ID', $result['detail']);
    }

    public function test_the_transport_identifies_itself(): void
    {
        $transport = new GmailApiTransport(app(GmailTokenProvider::class));

        $this->assertSame('gmail-api://', (string) $transport);
    }
}
