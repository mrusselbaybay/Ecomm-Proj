<?php

use App\Models\Conversation;
use App\Models\SupportTicket;
use App\Models\SupportTicketInternalNote;

it('requires authentication for Customer Service tickets', function () {
    $this->getJson('/api/customer-service/tickets')->assertUnauthorized();
});

it('allows each active public account role to create a Customer Service ticket', function (string $role) {
    $profile = match ($role) {
        'buyer' => makeBuyer(),
        'seller' => makeSeller(),
        'logistics' => makeLogistics(),
        'courier' => makeCourier(),
    };

    actingAsProfile($profile);

    $this->postJson('/api/customer-service/tickets', [
        'category' => 'account',
        'subject' => 'I need account assistance',
        'description' => 'Please help me understand the status of my account.',
    ])->assertCreated()
        ->assertJsonPath('data.status', 'submitted')
        ->assertJsonPath('data.messages.0.sender.name', 'You')
        ->assertJsonMissingPath('data.assignedAdminId');

    $ticket = SupportTicket::firstOrFail();
    $conversation = Conversation::where('support_ticket_id', $ticket->id)->firstOrFail();

    expect($conversation->type)->toBe('support')
        ->and($conversation->participantRecords)->toHaveCount(1)
        ->and($conversation->participantRecords->first()->user_id)->toBe($profile->id);
})->with(['buyer', 'seller', 'logistics', 'courier']);

it('prevents a customer from setting internal assignment priority or status', function () {
    $buyer = makeBuyer();
    $admin = makeAdmin();
    actingAsBuyer($buyer);

    $this->postJson('/api/customer-service/tickets', [
        'category' => 'account',
        'subject' => 'Trying internal fields',
        'description' => 'This request must not control the internal queue.',
        'assigned_admin_id' => $admin->id,
        'priority' => 'urgent',
        'status' => 'resolved',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['assigned_admin_id', 'priority', 'status']);

    expect(SupportTicket::count())->toBe(0);
});

it('hides another customers ticket instead of exposing it', function () {
    $owner = makeBuyer();
    $otherBuyer = makeBuyer();
    [$ticket] = makeSupportTicket($owner);
    actingAsBuyer($otherBuyer);

    $this->getJson("/api/customer-service/tickets/{$ticket->id}")->assertNotFound();
    $this->postJson("/api/customer-service/tickets/{$ticket->id}/messages", [
        'body' => 'Trying to enter another ticket',
    ])->assertForbidden();
});

it('allows an admin to claim an unassigned ticket', function () {
    $creator = makeBuyer();
    $admin = makeAdmin();
    [$ticket, $conversation] = makeSupportTicket($creator);
    actingAsProfile($admin);

    $this->patchJson("/api/admin/customer-service/tickets/{$ticket->id}/assignment")
        ->assertOk()
        ->assertJsonPath('data.assignedAdminId', $admin->id)
        ->assertJsonPath('data.status', 'open');

    expect($conversation->participantRecords()->where('user_id', $admin->id)->exists())->toBeTrue();
});

it('rejects an admin who is not assigned to the ticket', function () {
    $creator = makeBuyer();
    $assignedAdmin = makeAdmin();
    $otherAdmin = makeAdmin();
    [$ticket] = makeSupportTicket($creator, [
        'assigned_admin_id' => $assignedAdmin->id,
        'status' => 'open',
    ]);
    actingAsProfile($otherAdmin);

    $this->getJson("/api/admin/customer-service/tickets/{$ticket->id}")->assertNotFound();
    $this->postJson("/api/admin/customer-service/tickets/{$ticket->id}/reply", [
        'body' => 'I am not assigned.',
    ])->assertForbidden();
});

it('shows admin replies only as Platform Customer Service and never exposes internal notes', function () {
    $creator = makeBuyer();
    $admin = makeAdmin();
    [$ticket] = makeSupportTicket($creator, [
        'assigned_admin_id' => $admin->id,
        'status' => 'open',
    ]);
    actingAsProfile($admin);

    $this->postJson("/api/admin/customer-service/tickets/{$ticket->id}/internal-notes", [
        'body' => 'Private investigation details.',
    ])->assertCreated();
    $this->postJson("/api/admin/customer-service/tickets/{$ticket->id}/reply", [
        'body' => 'We are reviewing your concern.',
    ])->assertCreated();

    actingAsProfile($creator);

    $response = $this->getJson("/api/customer-service/tickets/{$ticket->id}")
        ->assertOk()
        ->assertJsonPath('data.messages.1.sender.name', 'Platform Customer Service')
        ->assertJsonMissingPath('data.assignedAdminId')
        ->assertJsonMissingPath('data.internalNotes');

    expect($response->json('data.messages.1'))->not->toHaveKey('senderId')
        ->and(SupportTicketInternalNote::count())->toBe(1);
});

it('records a resolution and creates a separate database notification', function () {
    $creator = makeBuyer();
    $admin = makeAdmin();
    [$ticket] = makeSupportTicket($creator, [
        'assigned_admin_id' => $admin->id,
        'status' => 'open',
    ]);
    actingAsProfile($admin);

    $this->postJson("/api/admin/customer-service/tickets/{$ticket->id}/resolve", [
        'resolution_summary' => 'The account restriction has been removed.',
    ])->assertOk()
        ->assertJsonPath('data.status', 'resolved')
        ->assertJsonPath('data.resolutionSummary', 'The account restriction has been removed.');

    $this->assertDatabaseHas('notifications', [
        'notifiable_id' => $creator->id,
    ]);
    expect($ticket->fresh()->resolved_at)->not->toBeNull();
});

it('rejects customer replies to a closed ticket until it is reopened', function () {
    $creator = makeBuyer();
    [$ticket] = makeSupportTicket($creator, ['status' => 'closed', 'closed_at' => now()]);
    actingAsProfile($creator);

    $this->postJson("/api/customer-service/tickets/{$ticket->id}/messages", [
        'body' => 'A closed ticket should reject this.',
    ])->assertUnprocessable();

    $this->postJson("/api/customer-service/tickets/{$ticket->id}/reopen")
        ->assertOk()
        ->assertJsonPath('data.status', 'reopened');

    $this->postJson("/api/customer-service/tickets/{$ticket->id}/messages", [
        'body' => 'This reply is allowed after reopening.',
    ])->assertCreated();
});
