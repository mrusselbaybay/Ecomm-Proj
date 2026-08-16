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
    public $appUrl;

    public function __construct($name, $reason)
    {
        $this->name = $name;
        $this->reason = $reason;
        $this->appUrl = config('app.url');
    }

    public function build()
    {
        return $this->subject('Your NEXMART Account Application')
                    ->view('emails.registration-rejected')
                    ->with([
                        'name' => $this->name,
                        'reason' => $this->reason,
                        'appUrl' => $this->appUrl
                    ]);
    }
}