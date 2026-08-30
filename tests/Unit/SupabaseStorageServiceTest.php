<?php

use App\Services\SupabaseStorageService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    config()->set('services.supabase.url', 'https://example.supabase.co');
    config()->set('services.supabase.service_role_key', 'service-role-key');
    config()->set('services.supabase.verify_ssl', true);
});

it('returns an absolute signed URL for a private storage object', function () {
    Http::fake([
        '*' => Http::response([
            'signedURL' => '/object/sign/documents/profile/resume.pdf?token=signed',
        ]),
    ]);

    $url = app(SupabaseStorageService::class)
        ->signedUrl('profile/resume.pdf');

    expect($url)->toBe(
        'https://example.supabase.co/storage/v1/object/sign/documents/profile/resume.pdf?token=signed'
    );
});

it('returns null when the storage service cannot be reached', function () {
    Http::fake(fn () => throw new ConnectionException('TLS unavailable'));

    $url = app(SupabaseStorageService::class)
        ->signedUrl('profile/resume.pdf');

    expect($url)->toBeNull();
});
