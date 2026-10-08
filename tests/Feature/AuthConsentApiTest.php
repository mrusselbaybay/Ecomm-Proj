<?php

use App\Models\ConsentRecord;
use Illuminate\Support\Str;

it('records guest cookie choices', function () {
    $guestId = (string) Str::uuid();

    $this->postJson('/api/consent/cookies', [
        'guest_id' => $guestId,
        'categories' => [
            'strictly_necessary' => true,
            'functional' => false,
            'analytics' => true,
            'marketing' => false,
        ],
        'action' => 'updated',
    ])->assertCreated()
        ->assertJsonPath('data.consentType', 'cookies')
        ->assertJsonPath('data.categories.analytics', true);

    expect(ConsentRecord::query()->where('guest_id', $guestId)->first())
        ->consent_type->toBe('cookies');
});

it('requires strictly necessary cookies', function () {
    $this->postJson('/api/consent/cookies', [
        'guest_id' => (string) Str::uuid(),
        'categories' => [
            'strictly_necessary' => false,
            'functional' => false,
            'analytics' => false,
            'marketing' => false,
        ],
    ])->assertUnprocessable()->assertJsonValidationErrors('categories.strictly_necessary');
});

it('records required terms and optional marketing separately', function () {
    $guestId = (string) Str::uuid();

    $this->postJson('/api/consent/terms', ['guest_id' => $guestId, 'accepted' => true])->assertCreated();
    $this->postJson('/api/consent/marketing', ['guest_id' => $guestId, 'accepted' => false])
        ->assertCreated()
        ->assertJsonPath('data.action', 'withdrawn');

    expect(ConsentRecord::query()->where('guest_id', $guestId)->count())->toBe(2);
});
