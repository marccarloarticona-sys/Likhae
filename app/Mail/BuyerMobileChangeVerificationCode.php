<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class BuyerMobileChangeVerificationCode extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly string $code)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Confirm your LIKHAE account mobile number change');
    }

    public function content(): Content
    {
        return new Content(view: 'auth.emails.buyer-mobile-change-verification-code');
    }
}
