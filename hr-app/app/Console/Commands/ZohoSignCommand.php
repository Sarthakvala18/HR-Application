<?php

namespace App\Console\Commands;

use App\Models\DocumentTemplate;
use App\Models\Employee;
use App\Services\Zoho\LetterService;
use App\Services\Zoho\ZohoSignClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

class ZohoSignCommand extends Command
{
    protected $signature = 'hr:zoho-sign
        {action=ping : ping, exchange, templates, verify, or preview}
        {--code= : Self Client grant code, for exchange}
        {--employee= : Employee id or name, for preview}
        {--template= : Template id, for verify}
        {--type= : relieving or experience; omit to preview both}';

    protected $description = 'Check the Zoho Sign connection and test letter templates';

    public function handle(ZohoSignClient $client): int
    {
        return match ($this->argument('action')) {
            'ping' => $this->ping($client),
            'exchange' => $this->exchange($client),
            'templates' => $this->templates($client),
            'verify' => $this->verify($client),
            'preview' => $this->preview(),
            default => $this->invalid(),
        };
    }

    private function invalid(): int
    {
        $this->error('Action must be one of: ping, exchange, templates, verify, preview');

        return self::FAILURE;
    }

    // -------------------------------------------------------------- exchange

    /**
     * Swaps a Self Client grant code for a refresh token and writes it straight
     * into .env.
     *
     * The token is never printed: it is a permanent credential, and echoing it
     * to a terminal puts it in scrollback and shell history.
     */
    private function exchange(ZohoSignClient $client): int
    {
        $code = trim((string) $this->option('code'));

        if ($code === '') {
            $this->error('Pass --code=<grant code from the API console>');

            return self::FAILURE;
        }

        $clientId = config('zoho.client_id');
        $clientSecret = config('zoho.client_secret');

        if (blank($clientId) || blank($clientSecret)) {
            $this->error('Set ZOHO_CLIENT_ID and ZOHO_CLIENT_SECRET in .env first.');

            return self::FAILURE;
        }

        $dc = ltrim((string) config('zoho.dc', 'com'), '.');

        $response = Http::asForm()->timeout(30)->post(
            'https://accounts.zoho.'.$dc.'/oauth/v2/token',
            [
                'grant_type' => 'authorization_code',
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'code' => $code,
            ],
        );

        $refreshToken = $response->json('refresh_token');

        if (blank($refreshToken)) {
            $this->error('Exchange failed: '.($response->json('error') ?? $response->body()));
            $this->newLine();
            $this->line('Common causes:');
            $this->line('  invalid_code      the code expired (they last ~3 minutes) or was already used');
            $this->line('  invalid_client    client id/secret mismatch, or the wrong data centre in ZOHO_DC');

            return self::FAILURE;
        }

        if (! $this->writeEnv('ZOHO_REFRESH_TOKEN', $refreshToken)) {
            $this->error('Could not write to .env. Add ZOHO_REFRESH_TOKEN manually.');

            return self::FAILURE;
        }

        $this->info('Refresh token saved to .env. It does not expire.');
        $this->newLine();

        // Prove it works rather than assuming.
        $this->call('config:clear');
        $result = app(ZohoSignClient::class)->ping();

        if ($result['ok']) {
            $this->info('Connected. Templates visible: '.$result['templates']);

            return self::SUCCESS;
        }

        $this->error('Saved, but the connection check failed: '.$result['detail']);

        return self::FAILURE;
    }

    /** Replaces or appends a key in .env, keeping a timestamped backup. */
    private function writeEnv(string $key, string $value): bool
    {
        $path = base_path('.env');

        if (! is_writable($path)) {
            return false;
        }

        copy($path, $path.'.bak-'.now()->format('Ymd-His'));

        $contents = file_get_contents($path);
        $line = $key.'='.$value;

        $updated = preg_replace(
            '/^'.preg_quote($key, '/').'=.*$/m',
            $line,
            $contents,
            1,
            $count,
        );

        if ($count === 0) {
            $updated = rtrim($contents, "\n")."\n".$line."\n";
        }

        return file_put_contents($path, $updated) !== false;
    }

    // ------------------------------------------------------------------ ping

    private function ping(ZohoSignClient $client): int
    {
        $this->line('Endpoint: '.$client->apiBase());
        $this->line('Credentials: '.($client->isConfigured()
            ? 'refresh token configured'
            : 'NO refresh token — falling back to the temporary access token'));
        $this->newLine();

        $result = $client->ping();

        if ($result['ok']) {
            $this->info('Connected. '.$result['detail']);
            $this->line('Templates visible: '.$result['templates']);

            return self::SUCCESS;
        }

        $this->error('Not connected: '.$result['detail']);
        $this->newLine();
        $this->warn('To fix, add these to hr-app/.env:');
        $this->line('  ZOHO_CLIENT_ID, ZOHO_CLIENT_SECRET, ZOHO_REFRESH_TOKEN');
        $this->line('Generate them at https://api-console.zoho.com (Self Client) with scope:');
        $this->line('  ZohoSign.documents.ALL, ZohoSign.templates.ALL');

        return self::FAILURE;
    }

    // ------------------------------------------------------------- templates

    private function templates(ZohoSignClient $client): int
    {
        try {
            $templates = $client->listTemplates();
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($templates === []) {
            $this->warn('No templates returned.');

            return self::SUCCESS;
        }

        $this->table(
            ['Template ID', 'Name'],
            collect($templates)->map(fn (array $t) => [
                $t['template_id'] ?? '?',
                $t['template_name'] ?? '?',
            ])->all(),
        );

        $this->newLine();
        $this->comment('Register an id against a department with hr:zoho-sign verify --template=<id>');

        return self::SUCCESS;
    }

    // ---------------------------------------------------------------- verify

    /**
     * Compares what a template actually declares against what the app has
     * mapped. This is the check that catches a renamed or mistyped field label
     * before it fails at send time.
     */
    private function verify(ZohoSignClient $client): int
    {
        $templateId = $this->option('template');

        if (blank($templateId)) {
            $this->error('Pass --template=<zoho template id>');

            return self::FAILURE;
        }

        try {
            $types = $client->templateFieldTypes($templateId);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $actual = array_keys($types);

        $this->info('Fields declared by the template:');
        foreach ($types as $label => $type) {
            $owner = in_array($type, [DocumentTemplate::TYPE_TEXT, DocumentTemplate::TYPE_DATE], true)
                ? 'we supply'
                : 'Zoho fills';
            $this->line(sprintf('  - %-16s %-12s %s', $label, $type, $owner));
        }

        $local = DocumentTemplate::where('zoho_template_id', $templateId)->first();

        if ($local === null) {
            $this->newLine();
            $this->warn('No local template row references this id yet.');

            return self::SUCCESS;
        }

        // Record the types first: they decide how each field gets filled.
        $local->update(['field_types' => $types]);

        $mapped = array_values($local->field_map ?? []);
        $wrong = array_diff($mapped, $actual);

        // Fields Zoho fills itself do not need a mapping, so their absence from
        // our map is not a gap.
        $unmappedButOurs = array_filter(
            array_diff($actual, $mapped),
            fn (string $label) => in_array(
                $types[$label],
                [DocumentTemplate::TYPE_TEXT, DocumentTemplate::TYPE_DATE],
                true,
            ),
        );

        $this->newLine();

        if ($wrong !== []) {
            $this->error('Mapped but NOT on the template: '.implode(', ', $wrong));
        }

        if ($unmappedButOurs !== []) {
            $this->error('Template expects values we do not map: '.implode(', ', $unmappedButOurs));
        }

        if ($wrong !== [] || $unmappedButOurs !== []) {
            return self::FAILURE;
        }

        $local->update(['verified_at' => now()]);
        $this->info('Field map verified against the live template. Marked verified.');

        return self::SUCCESS;
    }

    // --------------------------------------------------------------- preview

    /**
     * Dry run: shows exactly what would be sent, without calling Zoho. This
     * works with no credentials at all.
     */
    private function preview(): int
    {
        $employee = $this->resolveEmployee();

        if ($employee === null) {
            return self::FAILURE;
        }

        $service = app(LetterService::class);
        $type = $this->option('type');

        // With no --type, show every exit letter this person would receive.
        $templates = $type
            ? [DocumentTemplate::resolve($type, $employee->department_id)]
            : $service->exitTemplatesFor($employee);

        $templates = array_filter($templates);

        if ($templates === []) {
            $this->error('No letter templates registered for '
                .($employee->department?->name ?? 'this department').'.');

            return self::FAILURE;
        }

        $this->info('Employee: '.$employee->full_name
            .' ('.($employee->department?->name ?? 'no department').')');
        $this->newLine();

        $allReady = true;

        foreach ($templates as $template) {
            $allReady = $this->previewTemplate($service, $employee, $template) && $allReady;
        }

        return $allReady ? self::SUCCESS : self::FAILURE;
    }

    private function previewTemplate(LetterService $service, Employee $employee, DocumentTemplate $template): bool
    {
        $this->line('<options=bold>'.strtoupper($template->type).' — '.$template->name.'</>');
        $this->line('  Zoho template id: '.($template->zoho_template_id ?: 'NOT SET'));
        $this->line('  Verified against API: '.($template->isVerified() ? 'yes' : 'no'));

        if (empty($template->field_map)) {
            $this->warn('  No field map recorded. '.($template->notes ?: ''));
            $this->newLine();

            return false;
        }

        $payload = $service->buildPayload($employee, $template);

        $rows = [];

        foreach ($payload['text'] as $label => $value) {
            $rows[] = [$label, 'text', $value];
        }

        foreach ($payload['dates'] as $label => $value) {
            $rows[] = [$label, 'date', $value];
        }

        foreach ($payload['signatures'] as $label) {
            $type = $template->field_types[$label] ?? 'auto';

            $rows[] = [$label, strtolower($type), match ($type) {
                'Name' => 'auto-filled from the recipient',
                'Date' => 'stamped when signed',
                'Signature' => 'drawn at signing',
                default => 'filled by Zoho, not by the API',
            }];
        }

        if ($rows !== []) {
            $this->table(['Zoho field', 'Type', 'Value'], $rows);
        }

        $ok = true;

        if ($payload['missing'] !== []) {
            $this->error('  Missing required values: '.implode(', ', $payload['missing']));
            $ok = false;
        }

        if ($payload['unmapped'] !== []) {
            $this->warn('  Held but not on this template: '.implode(', ', $payload['unmapped']));
        }

        if ($template->notes) {
            $this->comment('  Note: '.$template->notes);
        }

        $this->newLine();

        return $ok;
    }

    private function resolveEmployee(): ?Employee
    {
        $needle = $this->option('employee');

        if (blank($needle)) {
            $this->error('Pass --employee=<id or name>');

            return null;
        }

        $employee = is_numeric($needle)
            ? Employee::find($needle)
            : Employee::where('full_name', 'like', '%'.$needle.'%')->first();

        if ($employee === null) {
            $this->error('No employee matched "'.$needle.'"');
        }

        return $employee;
    }
}
