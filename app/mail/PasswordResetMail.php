<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class PasswordResetMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $actionLink;

    public function __construct(string $actionLink)
    {
        $this->actionLink = $actionLink;
    }

    public function build()
    {
        return $this->subject('Reset your NEXMART password')
            ->markdown('emails.password-reset');
    }
}