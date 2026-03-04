<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class DeleteAccountCode extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $code)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Удаление аккаунта — код подтверждения',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.delete-account-code',
        );
    }
}
