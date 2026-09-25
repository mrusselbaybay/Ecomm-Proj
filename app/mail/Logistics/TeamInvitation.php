<?php

namespace App\Mail\Logistics;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class TeamInvitation extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $companyName,
        public string $inviterName,
        public string $roleLabel,
        public string $acceptUrl,
        public int $ttlDays,
    ) {}

    public function build()
    {
        return $this->subject("You're invited to join {$this->companyName} on BuyTheWay")
            ->view('emails.logistics.team-invitation');
    }
}
