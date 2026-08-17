<?php

namespace App\Mail\Logistics;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ApplicationAccepted extends Mailable
{
    use Queueable, SerializesModels;

    public string $courierName;
    public string $companyName;

    public function __construct(string $courierName, string $companyName)
    {
        $this->courierName = $courierName;
        $this->companyName = $companyName;
    }

    public function build()
    {
        return $this->subject("You're in! {$this->companyName} accepted your application")
            ->view('emails.logistics.application-accepted');
    }
}