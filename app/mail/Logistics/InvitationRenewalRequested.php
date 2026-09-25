<?php

namespace App\Mail\Logistics;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class InvitationRenewalRequested extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $companyName,
        public string $inviteeEmail,
        public string $teamUrl,
    ) {}

    public function build()
    {
        return $this->subject("{$this->inviteeEmail} asked for a new invitation")
            ->view('emails.logistics.invitation-renewal-requested');
    }
}
