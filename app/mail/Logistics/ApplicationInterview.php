<?php

namespace App\Mail\Logistics;

use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ApplicationInterview extends Mailable
{
    use Queueable, SerializesModels;

    public string $courierName;
    public string $companyName;
    public string $interviewAtFormatted;
    public ?string $notes;

    /**
     * $interviewAt is the raw wall-clock date/time the logistics staff
     * picked (no timezone offset) — it's formatted as-is, with no timezone
     * conversion, so the email shows exactly what they selected.
     */
    public function __construct(string $courierName, string $companyName, string $interviewAt, ?string $notes = null)
    {
        $this->courierName = $courierName;
        $this->companyName = $companyName;
        $this->interviewAtFormatted = Carbon::parse($interviewAt)->format('l, F j, Y \a\t g:i A');
        $this->notes = $notes;
    }

    public function build()
    {
        return $this->subject("{$this->companyName} wants to interview you")
            ->view('emails.logistics.application-interview');
    }
}
