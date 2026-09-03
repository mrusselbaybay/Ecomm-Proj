<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class SupportTicketUpdated extends Notification
{
    use Queueable;

    public function __construct(
        public string $event,
        public string $ticketId,
        public string $ticketNumber,
        public string $status,
        public string $preview,
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => $this->event,
            'ticket_id' => $this->ticketId,
            'ticket_number' => $this->ticketNumber,
            'status' => $this->status,
            'preview' => mb_substr($this->preview, 0, 160),
        ];
    }
}
