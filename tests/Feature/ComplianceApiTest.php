<?php

use App\Models\ConsentRecord;
use App\Models\DataSubjectRequest;
use App\Models\IpTakedownRequest;
use App\Models\SellerPayout;
use App\Services\Payments\SellerPayoutService;
use Illuminate\Support\Str;

it('records cookie choices for a guest before applying them', function () {
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
        ->consent_type->toBe('cookies')
        ->policy_version->toBe('[POLICY_VERSION]');
});

it('does not allow strictly necessary cookies to be disabled', function () {
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

it('stores separate required terms and optional marketing choices', function () {
    $guestId = (string) Str::uuid();

    $this->postJson('/api/consent/terms', ['guest_id' => $guestId, 'accepted' => true])->assertCreated();
    $this->postJson('/api/consent/marketing', ['guest_id' => $guestId, 'accepted' => false])
        ->assertCreated()
        ->assertJsonPath('data.action', 'withdrawn');

    expect(ConsentRecord::query()->where('guest_id', $guestId)->count())->toBe(2);
});

it('creates and protects a data subject request', function () {
    $buyer = makeBuyer();
    actingAsBuyer($buyer);

    $id = $this->postJson('/api/dsr/request', [
        'request_type' => 'access',
        'details' => 'Please provide my account data.',
    ])->assertCreated()->json('data.id');

    $this->getJson("/api/dsr/status/{$id}")
        ->assertOk()
        ->assertJsonPath('data.status', 'submitted');

    expect(DataSubjectRequest::query()->find($id)->user_id)->toBe($buyer->id);
});

it('accepts an intellectual property takedown request', function () {
    $this->postJson('/api/ip/takedown', [
        'claimant_name' => 'Rights Holder',
        'claimant_email' => 'rights@example.test',
        'listing_id' => 'listing-123',
        'work_description' => 'Original product photograph.',
        'evidence_url' => 'https://example.test/evidence',
        'statement' => 'I declare that this request is accurate.',
    ])->assertCreated()->assertJsonPath('data.status', 'submitted');

    expect(IpTakedownRequest::query()->count())->toBe(1);
});

it('calculates the one percent withholding after annual gross exceeds the threshold', function () {
    $seller = makeSeller();
    SellerPayout::query()->create([
        'seller_id' => $seller->id,
        'gross_amount' => 499500,
        'withholding_tax' => 0,
        'net_amount' => 499500,
        'period' => now()->format('Y-m'),
        'created_at' => now(),
    ]);

    $payout = app(SellerPayoutService::class)->record($seller->id, 100000);

    expect($payout->gross_amount)->toBe('1000.00')
        ->and($payout->withholding_tax)->toBe('10.00')
        ->and($payout->net_amount)->toBe('990.00');
});

it('allows a platform admin to record a breach with a 72 hour deadline', function () {
    $admin = makeAdmin();
    actingAsProfile($admin);

    $this->postJson('/api/admin/breach/report', [
        'detected_at' => now()->subHour()->toIso8601String(),
        'affected_count' => 12,
        'description' => 'Test incident for workflow verification.',
        'status' => 'investigating',
    ])->assertCreated()
        ->assertJsonPath('data.status', 'investigating')
        ->assertJsonStructure(['data' => ['npcReportDueAt']]);
});

it('prunes cookie consent records after the retention period', function () {
    ConsentRecord::factory()->create(['created_at' => now()->subMonths(25)]);
    $recent = ConsentRecord::factory()->create(['created_at' => now()->subMonths(3)]);

    $this->artisan('compliance:prune-retained-data')->assertSuccessful();

    expect(ConsentRecord::query()->count())->toBe(1)
        ->and(ConsentRecord::query()->value('id'))->toBe($recent->id);
});
