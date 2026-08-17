<?php

namespace App\Mail\Logistics;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ApplicationRejected extends Mailable
{
    use Queueable, SerializesModels;

    public string $courierName;
    public string $companyName;
    public ?string $reason;

    public function __construct(string $courierName, string $companyName, ?string $reason)
    {
        $this->courierName = $courierName;
        $this->companyName = $companyName;
        $this->reason = $reason;
    }

    public function build()
    {
        return $this->subject("Update on your application to {$this->companyName}")
            ->view('emails.logistics.application-rejected');
    }
}