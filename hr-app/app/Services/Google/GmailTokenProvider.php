<?php

namespace App\Services\Google;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Exchanges the stored refresh token for a short-lived Gmail access token.
 *
 * Google access tokens last an hour; the refresh token does not expire unless
 * it is revoked or left unused for six months. Tokens are cached just under the
 * hour so a burst of letters costs one token request rather than one per email.
 */
class GmailTokenProvider
{
    private const TOKEN_URI = 'https://oauth2.googleapis.com/token';

    private const AUTH_URI = 'https://accounts.google.com/o/oauth2/auth';

    /**
     * Send-only. Deliberately not https://mail.google.com/, which is what
     * Gmail's SMTP requires and which would also grant read and delete over
     * the whole HR mailbox.
     */
    public const SCOPE = 'https://www.googleapis.com/auth/gmail.send';

    private const CACHE_KEY = 'google.gmail.access_token';

    private const CACHE_TTL = 3300; // 55 minutes

    /** The consent URL a human opens once to authorise the mailbox. */
    public function consentUrl(): string
    {
        return self::AUTH_URI.'?'.http_build_query([
            'client_id' => $this->config('client_id'),
            'redirect_uri' => $this->config('redirect_uri'),
            'response_type' => 'code',
            'scope' => self::SCOPE,
            // offline + consent are what make Google return a refresh token;
            // without them the callback yields an access token only.
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'false',
        ]);
    }

    /**
     * Swaps a one-time authorisation code for a refresh token.
     *
     * @return array{ok: bool, refresh_token?: string, detail?: string}
     */
    public function exchangeCode(string $code): array
    {
        $response = Http::asForm()->post(self::TOKEN_URI, [
            'code' => $code,
            'client_id' => $this->config('client_id'),
            'client_secret' => $this->config('client_secret'),
            'redirect_uri' => $this->config('redirect_uri'),
            'grant_type' => 'authorization_code',
        ]);

        if ($response->failed()) {
            return ['ok' => false, 'detail' => $this->errorFrom($response->json(), $response->status())];
        }

        $refresh = $response->json('refresh_token');

        if (blank($refresh)) {
            return [
                'ok' => false,
                'detail' => 'Google returned no refresh token. This happens when the mailbox has already '
                    .'granted consent: revoke it at myaccount.google.com/permissions and run this again.',
            ];
        }

        return ['ok' => true, 'refresh_token' => $refresh];
    }

    /** A usable access token, from cache where possible. */
    public function accessToken(): string
    {
        $cached = Cache::get(self::CACHE_KEY);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $refresh = $this->config('refresh_token');

        if (blank($refresh)) {
            throw new RuntimeException(
                'No Google refresh token. Run "php artisan hr:gmail-auth url", authorise the mailbox, '
                .'then "php artisan hr:gmail-auth exchange --code=<code>".'
            );
        }

        $response = Http::asForm()->post(self::TOKEN_URI, [
            'client_id' => $this->config('client_id'),
            'client_secret' => $this->config('client_secret'),
            'refresh_token' => $refresh,
            'grant_type' => 'refresh_token',
        ]);

        if ($response->failed()) {
            throw new RuntimeException(
                'Could not refresh the Google access token: '
                .$this->errorFrom($response->json(), $response->status())
            );
        }

        $token = (string) $response->json('access_token');

        if ($token === '') {
            throw new RuntimeException('Google returned an empty access token.');
        }

        Cache::put(self::CACHE_KEY, $token, self::CACHE_TTL);

        return $token;
    }

    /** Drops the cached token, so the next call mints a fresh one. */
    public function forgetCachedToken(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Checks the credentials are present and the refresh token still works,
     * without sending anything.
     *
     * @return array{ok: bool, detail: string}
     */
    public function check(): array
    {
        foreach (['client_id', 'client_secret', 'redirect_uri'] as $key) {
            if (blank($this->config($key))) {
                return ['ok' => false, 'detail' => 'GOOGLE_'.strtoupper($key).' is not set in .env.'];
            }
        }

        if (blank($this->config('refresh_token'))) {
            return ['ok' => false, 'detail' => 'No refresh token yet. Run hr:gmail-auth url to authorise.'];
        }

        try {
            $this->forgetCachedToken();
            $this->accessToken();

            return ['ok' => true, 'detail' => 'Refresh token works; access token minted.'];
        } catch (RuntimeException $e) {
            return ['ok' => false, 'detail' => $e->getMessage()];
        }
    }

    private function config(string $key): ?string
    {
        $value = config('services.google.'.$key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Google reports OAuth problems as error/error_description rather than a
     * message field, so surface both or the caller sees only a status code.
     */
    private function errorFrom(mixed $body, int $status): string
    {
        if (! is_array($body)) {
            return 'HTTP '.$status;
        }

        $parts = array_filter([
            $body['error'] ?? null,
            $body['error_description'] ?? null,
        ], fn ($v) => is_string($v) && $v !== '');

        return $parts === [] ? 'HTTP '.$status : implode(': ', $parts);
    }
}
