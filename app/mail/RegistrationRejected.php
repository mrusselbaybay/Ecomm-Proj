<?php
// app/Mail/RegistrationRejected.php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class RegistrationRejected extends Mailable
{
    use Queueable, SerializesModels;

    public $name;
    public $reason;

    public function __construct($name, $reason)
    {
        $this->name = $name;
        $this->reason = $reason;
    }

    public function build()
    {
        return $this->subject('Your NEXMART Account Application')
                    ->markdown('emails.registration-rejected');
    }
}