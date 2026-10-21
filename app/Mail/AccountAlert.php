<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AccountAlert extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $type,
        public readonly string $title,
        public readonly string $body,
        public readonly ?string $actionUrl,
        public readonly string $actionLabel = 'View notification',
        public readonly ?string $userName = null,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'LIKHAE alert: '.$this->title);
    }

    public function content(): Content
    {
        $view = str_starts_with($this->type, 'RIDER_MODERATION_')
            ? 'rider.emails.moderation-alert'
            : 'rider.emails.assignment-alert';

        return new Content(view: $view);
    }
}
