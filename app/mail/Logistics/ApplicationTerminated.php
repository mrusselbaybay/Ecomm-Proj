<?php

namespace App\Mail\Logistics;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to a courier when a logistics company ends their engagement from
 * the Rider Applications page ("Fire"). Their accepted application is
 * withdrawn and they are removed from that company's delivery areas.
 */
class ApplicationTerminated extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $courierName,
        public string $companyName,
        public ?string $reason,
    ) {}

    public function build()
    {
        return $this->subject("Your engagement with {$this->companyName} has ended")
            ->view('emails.logistics.application-terminated');
    }
}
