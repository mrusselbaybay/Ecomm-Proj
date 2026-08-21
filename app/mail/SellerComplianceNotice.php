<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SellerComplianceNotice extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $sellerName,
        public string $productName,
        public string $action,
        public ?string $reason,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'NEXMART Seller Compliance Notice',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.seller-compliance-notice',
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
