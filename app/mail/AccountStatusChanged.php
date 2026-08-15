<?php
// app/Mail/AccountStatusChanged.php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class AccountStatusChanged extends Mailable
{
    use Queueable, SerializesModels;

    public $name;
    public $status;

    public function __construct($name, $status)
    {
        $this->name = $name;
        $this->status = $status;
    }

    public function build()
    {
        $statusLabels = [
            'active' => 'activated',
            'suspended' => 'suspended',
            'deactivated' => 'deactivated'
        ];
        
        $label = $statusLabels[$this->status] ?? $this->status;
        
        return $this->subject("Your NEXMART Account Has Been {$label}")
                    ->markdown('emails.account-status-changed');
    }
}