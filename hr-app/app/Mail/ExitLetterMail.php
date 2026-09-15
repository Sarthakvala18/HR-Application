<?php

namespace App\Mail;

use App\Models\Employee;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Delivers the completed exit letters to a departing employee.
 *
 * Letters are attached as filled PDFs rather than sent through Zoho Sign,
 * because the Zoho licence permits creating documents but not dispatching them.
 */
class ExitLetterMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  array<int, array{name: string, pdf: string}>  $letters
     */
    public function __construct(
        public readonly Employee $employee,
        public readonly array $letters,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                config('zoho.sign.from_address', config('mail.from.address')),
                config('mail.from.name', 'Coach Foundation HR'),
            ),
            subject: count($this->letters) > 1
                ? 'Your relieving and experience letters'
                : 'Your '.strtolower($this->letters[0]['type'] ?? 'exit').' letter',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.exit-letters',
            with: [
                'name' => $this->employee->displayName(),
                'letters' => $this->letters,
                'hrEmail' => config('zoho.sign.from_address', config('mail.from.address')),
            ],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return array_map(
            fn (array $letter) => Attachment::fromData(
                fn () => $letter['pdf'],
                $letter['name'],
            )->withMime('application/pdf'),
            $this->letters,
        );
    }
}
