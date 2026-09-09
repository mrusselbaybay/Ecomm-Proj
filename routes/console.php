<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// See app/Console/Commands/AutoDeliverStaleOrders.php for why this
// exists and what it does and doesn't rely on.
Schedule::command('orders:auto-deliver')->daily();
