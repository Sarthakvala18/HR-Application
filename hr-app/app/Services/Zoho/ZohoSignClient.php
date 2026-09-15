<?php

namespace App\Services\Zoho;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Thin client for the Zoho Sign REST API.
 *
 * Zoho access tokens expire after one hour, so nothing here treats a stored
 * access token as the source of truth: it refreshes from the refresh token and
 * caches the result just short of expiry.
 */
class ZohoSignClient
{
    private const TOKEN_CACHE_KEY = 'zoho.sign.access_token';

    private function dc(): string
    {
        return 'zoho.'.ltrim((string) config('zoho.dc', 'com'), '.');
    }

    public function apiBase(): string
    {
        return 'https://sign.'.$this->dc().'/api/v1';
    }

    private function accountsUrl(): string
    {
        return 'https://accounts.'.$this->dc().'/oauth/v2/token';
    }

    /** True when enough configuration exists to even attempt a call. */
    public function isConfigured(): bool
    {
        return filled(config('zoho.refresh_token'))
            && filled(config('zoho.client_id'))
            && filled(config('zoho.client_secret'));
    }

    /**
     * A usable access token, refreshed on demand.
     *
     * @throws RuntimeException when no credential can produce one
     */
    public function accessToken(): string
    {
        if ($this->isConfigured()) {
            return Cache::remember(
                self::TOKEN_CACHE_KEY,
                now()->addMinutes(50),
                fn () => $this->refreshAccessToken(),
            );
        }

        $override = config('zoho.access_token_override');

        if (filled($override)) {
            return $override;
        }

        throw new RuntimeException(
            'Zoho Sign is not configured. Set ZOHO_CLIENT_ID, ZOHO_CLIENT_SECRET and ZOHO_REFRESH_TOKEN.',
        );
    }

    private function refreshAccessToken(): string
    {
        $response = Http::asForm()
            ->timeout((int) config('zoho.sign.timeout', 30))
            ->post($this->accountsUrl(), [
                'refresh_token' => config('zoho.refresh_token'),
                'client_id' => config('zoho.client_id'),
                'client_secret' => config('zoho.client_secret'),
                'grant_type' => 'refresh_token',
            ]);

        $token = $response->json('access_token');

        if (blank($token)) {
            throw new RuntimeException(
                'Zoho token refresh failed: '.($response->json('error') ?? $response->body()),
            );
        }

        return $token;
    }

    private function request(): PendingRequest
    {
        return Http::withHeaders([
            'Authorization' => 'Zoho-oauthtoken '.$this->accessToken(),
        ])->timeout((int) config('zoho.sign.timeout', 30));
    }

    /**
     * Connection check. Returns a diagnostic array rather than throwing, so a
     * failure can be reported plainly instead of as a stack trace.
     *
     * @return array{ok: bool, detail: string, templates: int|null}
     */
    public function ping(): array
    {
        try {
            $response = $this->request()->get($this->apiBase().'/templates', [
                'data' => json_encode(['page_context' => ['row_count' => 1]]),
            ]);
        } catch (Throwable $e) {
            return ['ok' => false, 'detail' => $e->getMessage(), 'templates' => null];
        }

        if ($response->successful() && $response->json('status') !== 'failure') {
            return [
                'ok' => true,
                'detail' => 'Authenticated against '.$this->apiBase(),
                'templates' => (int) ($response->json('page_context.total_count') ?? 0),
            ];
        }

        $code = $response->json('code') ?? $response->status();
        $message = $response->json('message') ?? $response->body();

        return [
            'ok' => false,
            'detail' => trim($code.': '.$message),
            'templates' => null,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function listTemplates(int $rowCount = 50): array
    {
        $response = $this->request()->get($this->apiBase().'/templates', [
            'data' => json_encode(['page_context' => ['row_count' => $rowCount]]),
        ]);

        $this->assertOk($response, 'list templates');

        return $response->json('templates', []);
    }

    /** Full template detail, including field definitions and recipient actions. */
    public function getTemplate(string $templateId): array
    {
        $response = $this->request()->get($this->apiBase().'/templates/'.$templateId);

        $this->assertOk($response, 'fetch template '.$templateId);

        return $response->json('templates', []);
    }

    /**
     * The recipient slots a template defines.
     *
     * Zoho requires each action to carry the template's own `action_id`; a
     * document created without one is rejected, so recipients must be matched
     * to these slots rather than invented.
     *
     * @return array<int, array<string, mixed>>
     */
    public function templateActions(string $templateId): array
    {
        return $this->getTemplate($templateId)['actions'] ?? [];
    }

    /**
     * The field labels a template actually declares.
     *
     * This is what mappings should be verified against, rather than what a
     * screenshot appears to show.
     *
     * @return array<int, string>
     */
    public function templateFieldNames(string $templateId): array
    {
        return array_keys($this->templateFieldTypes($templateId));
    }

    /**
     * Field label => Zoho field type for a template.
     *
     * The type matters: Textfield and CustomDate are supplied by the API, while
     * Name, Date and Signature are filled by Zoho from the recipient or during
     * signing. A label alone cannot tell you which.
     *
     * @return array<string, string>
     */
    /**
     * Field label => geometry and type, as recorded by Zoho.
     *
     * These coordinates are what let the app stamp values onto the blank
     * template PDF itself, which is how letters go out while the licence
     * forbids sending through Zoho.
     *
     * A label can appear several times in one letter (a name repeated in the
     * body, for instance), so every placement is kept. Stamping only the first
     * would leave the other blanks empty.
     *
     * @return array<string, array<int, array{type: string, page: int, x: float, y: float, width: float, height: float}>>
     */
    public function templateFieldLayout(string $templateId): array
    {
        $template = $this->getTemplate($templateId);

        $layout = [];

        foreach ($template['document_fields'] ?? [] as $document) {
            foreach ($document['fields'] ?? [] as $field) {
                $label = $field['field_label'] ?? $field['field_name'] ?? null;

                if ($label === null) {
                    continue;
                }

                $layout[$label][] = [
                    'type' => $field['field_type_name'] ?? 'Unknown',
                    'page' => (int) ($field['page_no'] ?? 0),
                    'x' => (float) ($field['x_coord'] ?? 0),
                    'y' => (float) ($field['y_coord'] ?? 0),
                    'width' => (float) ($field['abs_width'] ?? 0),
                    'height' => (float) ($field['abs_height'] ?? 0),
                ];
            }
        }

        return $layout;
    }

    public function templateFieldTypes(string $templateId): array
    {
        $template = $this->getTemplate($templateId);

        $types = [];

        foreach ($template['document_fields'] ?? [] as $document) {
            foreach ($document['fields'] ?? [] as $field) {
                $label = $field['field_label'] ?? $field['field_name'] ?? null;

                if ($label === null) {
                    continue;
                }

                // A field placed twice keeps its first recorded type.
                $types[$label] ??= $field['field_type_name'] ?? 'Unknown';
            }
        }

        return $types;
    }

    /**
     * Creates a document from a template, optionally sending it immediately.
     *
     * @param  array<string, string>  $textFields  Zoho field label => value
     * @param  array<string, string>  $dateFields  Zoho field label => value
     * @param  array<int, array<string, mixed>>  $recipients
     */
    public function createDocumentFromTemplate(
        string $templateId,
        array $textFields,
        array $dateFields,
        array $recipients,
        bool $send = false,
    ): array {
        $actions = [];

        foreach ($recipients as $recipient) {
            $actions[] = array_filter([
                'action_id' => $recipient['action_id'] ?? null,
                'action_type' => $recipient['action_type'] ?? 'SIGN',
                'recipient_name' => $recipient['name'] ?? null,
                'recipient_email' => $recipient['email'] ?? null,
                'verify_recipient' => false,
            ], fn ($value) => $value !== null);
        }

        // Zoho rejects the whole request with "Extra key found" if the payload
        // carries anything outside this shape, so it is kept minimal and the
        // document name is passed as its own form field.
        $payload = [
            'templates' => [
                'field_data' => [
                    'field_text_data' => (object) $textFields,
                    'field_boolean_data' => (object) [],
                    'field_date_data' => (object) $dateFields,
                ],
                'actions' => $actions,
            ],
        ];

        // Zoho rejects a document_name field here outright ("Extra key found");
        // the request is named after the template, and renaming is a separate
        // call once the request exists.
        $response = $this->request()->asMultipart()->post(
            $this->apiBase().'/templates/'.$templateId.'/createdocument',
            [
                ['name' => 'data', 'contents' => json_encode($payload)],
                ['name' => 'is_quicksend', 'contents' => $send ? 'true' : 'false'],
            ],
        );

        $this->assertOk($response, 'create document from template');

        return $response->json('requests', []);
    }

    private function assertOk(Response $response, string $action): void
    {
        if (! $response->failed() && $response->json('status') !== 'failure') {
            return;
        }

        $message = $response->json('message') ?? $response->body();

        // A plan limit is not a bug in the request, and reads keep working, so
        // it is worth naming plainly rather than leaving it to look like one.
        if (str_contains(strtolower((string) $message), 'upgrade zoho sign license')) {
            throw new RuntimeException(
                'Zoho Sign plan does not allow sending documents via the API. '
                .'Reading templates works; creating or sending a document needs a '
                .'plan with API sending enabled. Zoho said: '.$message,
            );
        }

        throw new RuntimeException('Zoho Sign failed to '.$action.': '.$message);
    }
}
