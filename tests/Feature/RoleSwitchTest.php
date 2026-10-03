<?php

use App\Models\SellerDetail;
use App\Services\FileStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    // Supabase-owned tables the sqlite test schema doesn't migrate.
    if (! Schema::hasTable('documents')) {
        Schema::create('documents', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('owner_kind');
            $table->string('profile_id')->nullable();
            $table->string('logistics_company_id')->nullable();
            $table->string('doc_type');
            $table->string('id_type')->nullable();
            $table->string('storage_path');
            $table->string('mime_type')->nullable();
            $table->string('status')->default('pending');
            $table->string('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    if (! Schema::hasTable('status_audit_log')) {
        Schema::create('status_audit_log', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type');
            $table->string('entity_id');
            $table->string('old_status')->nullable();
            $table->string('new_status')->nullable();
            $table->text('reason')->nullable();
            $table->string('changed_by')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    Mail::fake();
    $this->mock(FileStorage::class, fn ($mock) => $mock->shouldReceive('upload')->andReturn('/storage/test-file'));
});

function sellerApplication(array $overrides = []): array
{
    return array_merge([
        'business_name' => 'Juan Store',
        'line_of_business' => 'Pet Supplies',
        'id_type' => 'Passport',
        'id_file' => UploadedFile::fake()->create('id.pdf', 100, 'application/pdf'),
        'business_permit' => UploadedFile::fake()->create('permit.pdf', 100, 'application/pdf'),
    ], $overrides);
}

it('switches a seller to buyer and back instantly', function () {
    $seller = makeSeller();
    actingAsSeller($seller);

    $this->postJson('/api/account/role/switch')->assertOk()->assertJsonPath('data.active_role', 'buyer');
    expect($seller->refresh()->role)->toBe('buyer');

    $this->postJson('/api/account/role/switch')->assertOk()->assertJsonPath('data.active_role', 'seller');
    expect($seller->refresh()->role)->toBe('seller');
});

it('blocks a buyer without an approved application from switching to seller', function () {
    actingAsBuyer($buyer = makeBuyer());

    $this->postJson('/api/account/role/switch')->assertStatus(409);
    expect($buyer->refresh()->role)->toBe('buyer');
});

it('requires business info and all documents for a seller application', function () {
    actingAsBuyer(makeBuyer());

    $this->post('/api/account/role/seller-application', [], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['business_name', 'line_of_business', 'id_file', 'id_type', 'business_permit']);
});

it('creates a pending application that admin approval turns into the seller capability', function () {
    $buyer = makeBuyer();
    actingAsBuyer($buyer);

    $this->post('/api/account/role/seller-application', sellerApplication(), ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('data.seller_application.status', 'pending')
        ->assertJsonPath('data.can_sell', false);

    expect($buyer->refresh()->role)->toBe('buyer');
    $this->postJson('/api/account/role/switch')->assertStatus(409);

    actingAsProfile(makeAdmin());
    $this->postJson("/api/admin/registrations/{$buyer->id}/approve")->assertOk();

    $buyer->refresh();
    expect($buyer->status)->toBe('approved');
    expect($buyer->account_status)->toBe('active');
    expect(SellerDetail::find($buyer->id)->application_status)->toBe('approved');

    actingAsBuyer($buyer);
    $this->postJson('/api/account/role/switch')->assertOk()->assertJsonPath('data.active_role', 'seller');
});

it('reuses the valid id on file and keeps the buyer account active on rejection', function () {
    $buyer = makeBuyer();
    \Illuminate\Support\Facades\DB::table('documents')->insert([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'owner_kind' => 'profile',
        'profile_id' => $buyer->id,
        'doc_type' => 'valid_id',
        'id_type' => 'UMID',
        'storage_path' => 'profile/x/valid_id.pdf',
        'status' => 'approved',
        'created_at' => now(),
    ]);
    actingAsBuyer($buyer);

    $this->post('/api/account/role/seller-application', sellerApplication(['id_file' => null, 'id_type' => null]), ['Accept' => 'application/json'])
        ->assertCreated();

    actingAsProfile(makeAdmin());
    $this->postJson("/api/admin/registrations/{$buyer->id}/reject", ['reason' => 'Blurry permit'])->assertOk();

    $buyer->refresh();
    expect($buyer->account_status)->toBe('active');
    expect(SellerDetail::find($buyer->id)->application_status)->toBe('rejected');
});
