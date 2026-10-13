<?php

namespace App\Mail;

use App\Models\Admin\SellerComplianceCase;
use App\Models\Seller\Product;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SellerProductWarning extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Product $product,
        public readonly SellerComplianceCase $case,
        public readonly string $category,
        public readonly string $reason,
        public readonly string $correctiveAction,
        public readonly ?string $evidence,
        public readonly string $actionUrl,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'LIKHAE product warning: '.$this->product->name);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.seller.product-warning',
            with: [
                'product' => $this->product,
                'case' => $this->case,
                'category' => $this->category,
                'reason' => $this->reason,
                'correctiveAction' => $this->correctiveAction,
                'evidence' => $this->evidence,
                'actionUrl' => $this->actionUrl,
            ],
        );
    }
}
