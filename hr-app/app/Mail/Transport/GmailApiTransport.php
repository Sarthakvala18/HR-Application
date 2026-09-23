<?php

namespace App\Mail\Transport;

use App\Services\Google\GmailTokenProvider;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

/**
 * Sends mail through the Gmail REST API rather than SMTP.
 *
 * Adapter: presents Gmail's HTTP send endpoint as a Symfony Mailer transport,
 * so Mailables, attachments and queues work unchanged.
 *
 * Why not SMTP with XOAUTH2, which Symfony already supports? Gmail's SMTP
 * server only accepts the https://mail.google.com/ scope, which grants read and
 * delete over the entire mailbox. The REST API accepts gmail.send, so the
 * application can send and do nothing else. The extra code here buys that
 * restriction.
 */
class GmailApiTransport extends AbstractTransport
{
    private const ENDPOINT = 'https://gmail.googleapis.com/gmail/v1/users/me/messages/send';

    public function __construct(private readonly GmailTokenProvider $tokens)
    {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $response = Http::withToken($this->tokens->accessToken())
            ->timeout(30)
            ->post(self::ENDPOINT, [
                'raw' => $this->base64Url($message->toString()),
            ]);

        // An expired or revoked grant surfaces as 401. Retry once with a fresh
        // token: the cached one can outlive its usefulness if the mailbox
        // re-consented or the clock drifted.
        if ($response->status() === 401) {
            $this->tokens->forgetCachedToken();

            $response = Http::withToken($this->tokens->accessToken())
                ->timeout(30)
                ->post(self::ENDPOINT, [
                    'raw' => $this->base64Url($message->toString()),
                ]);
        }

        if ($response->failed()) {
            throw new TransportException(
                'Gmail refused the message: '.$this->errorFrom($response->json(), $response->status())
            );
        }

        $id = $response->json('id');

        if (is_string($id) && $id !== '') {
            $message->setMessageId($id);
        }
    }

    /**
     * Gmail expects the RFC 2822 message base64url encoded: the standard
     * alphabet's + and / are replaced and the padding dropped.
     */
    private function base64Url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /**
     * Gmail nests the real cause under error.message, with the useful detail in
     * error.errors[].reason. A bare status code here would make a scope problem
     * indistinguishable from a quota one.
     */
    private function errorFrom(mixed $body, int $status): string
    {
        if (! is_array($body) || ! isset($body['error'])) {
            return 'HTTP '.$status;
        }

        $error = $body['error'];

        if (is_string($error)) {
            return $error.' (HTTP '.$status.')';
        }

        $message = $error['message'] ?? 'HTTP '.$status;
        $reasons = [];

        foreach ($error['errors'] ?? [] as $entry) {
            if (isset($entry['reason']) && is_string($entry['reason'])) {
                $reasons[] = $entry['reason'];
            }
        }

        return $reasons === []
            ? $message.' (HTTP '.$status.')'
            : $message.' ['.implode(', ', array_unique($reasons)).'] (HTTP '.$status.')';
    }

    public function __toString(): string
    {
        return 'gmail-api://';
    }
}
