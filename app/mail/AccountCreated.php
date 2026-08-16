<?php
// app/Mail/AccountCreated.php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class AccountCreated extends Mailable
{
    use Queueable, SerializesModels;

    public $name;
    public $email;
    public $password;
    public $role;
    public $roleLabel;
    public $appUrl;

    public function __construct($name, $email, $password, $role)
    {
        $this->name = $name;
        $this->email = $email;
        $this->password = $password;
        $this->role = $role;
        $this->appUrl = config('app.url');
        
        $roleLabels = [
            'admin' => 'Platform Administrator',
            'logistics_admin' => 'Logistics Admin'
        ];
        
        $this->roleLabel = $roleLabels[$role] ?? $role;
    }

    public function build()
    {
        return $this->subject("Your NEXMART {$this->roleLabel} Account Has Been Created")
                    ->view('emails.account-created')
                    ->with([
                        'name' => $this->name,
                        'email' => $this->email,
                        'password' => $this->password,
                        'role' => $this->role,
                        'roleLabel' => $this->roleLabel,
                        'appUrl' => $this->appUrl
                    ]);
    }
}