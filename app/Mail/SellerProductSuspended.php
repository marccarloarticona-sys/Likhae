<?php

namespace App\Mail;

use App\Models\Admin\SellerComplianceCase;
use App\Models\Seller\Product;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SellerProductSuspended extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Product $product,
        public readonly SellerComplianceCase $case,
        public readonly string $reason,
        public readonly string $actionUrl,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'LIKHAE product suspended: '.$this->product->name);
    }

    public function content(): Content
    {
        $variants = $this->product->variants->where('is_active', true);
        $prices = $variants->map(fn ($variant): float => (float) $variant->price);
        $priceSummary = $prices->isEmpty()
            ? 'Not available'
            : ($prices->min() === $prices->max()
                ? 'PHP '.number_format($prices->min(), 2)
                : 'PHP '.number_format($prices->min(), 2).' - PHP '.number_format($prices->max(), 2));

        return new Content(
            view: 'seller.emails.product-suspended',
            with: [
                'product' => $this->product,
                'case' => $this->case,
                'reason' => $this->reason,
                'actionUrl' => $this->actionUrl,
                'priceSummary' => $priceSummary,
                'skuList' => $variants->pluck('sku')->filter()->take(5)->implode(', '),
                'totalStock' => $variants->sum('stock'),
            ],
        );
    }
}
