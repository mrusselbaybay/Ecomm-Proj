<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('messages:prune-staged-attachments')->daily();

// See app/Console/Commands/AutoDeliverStaleOrders.php for why this
// exists and what it does and doesn't rely on.
Schedule::command('orders:auto-deliver')->daily();

// Buyer never clicked "Order Received" within 7 days of delivery →
// confirm on their behalf and release escrow.
Schedule::command('orders:auto-confirm-receipt')->daily();
