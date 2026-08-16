<?php
// app/Mail/RegistrationApproved.php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class RegistrationApproved extends Mailable
{
    use Queueable, SerializesModels;

    public $name;
    public $appUrl;

    public function __construct($name)
    {
        $this->name = $name;
        $this->appUrl = config('app.url');
    }

    public function build()
    {
        return $this->subject('Welcome to NEXMART! Your Account Has Been Approved')
                    ->view('emails.registration-approved')
                    ->with([
                        'name' => $this->name,
                        'appUrl' => $this->appUrl
                    ]);
    }
}