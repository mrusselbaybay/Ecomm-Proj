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
    public $statusLabel;
    public $reason;
    public $appUrl;

    public function __construct($name, $status, $reason = null)
    {
        $this->name = $name;
        $this->status = $status;
        $this->reason = $reason ?? 'No specific reason provided.';
        $this->appUrl = config('app.url');
        
        $statusLabels = [
            'active' => 'Activated',
            'suspended' => 'Suspended',
            'deactivated' => 'Deactivated'
        ];
        
        $this->statusLabel = $statusLabels[$status] ?? $status;
    }

    public function build()
    {
        return $this->subject("Your NEXMART Account Has Been {$this->statusLabel}")
                    ->view('emails.account-status-changed');
    }
}