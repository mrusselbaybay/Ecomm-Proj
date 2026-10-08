<?php

it('provides working legal links to both authentication portals', function (string $path, string $view) {
    $this->get($path)
        ->assertOk()
        ->assertViewIs($view)
        ->assertSee('LEGAL_TERMS_URL: '.json_encode(route('legal.terms')), false)
        ->assertSee('LEGAL_PRIVACY_URL: '.json_encode(route('legal.privacy')), false);
})->with([
    'buyer login' => ['/login', 'auth.app'],
    'buyer signup' => ['/signup', 'auth.app'],
    'logistics login' => ['/logistics-login', 'auth.logistics'],
    'logistics signup' => ['/logistics-signup', 'auth.logistics'],
]);

it('loads the cookie banner when a visitor first lands on a standalone public page', function (string $path) {
    $this->get($path)->assertOk()->assertSee('cookie-consent', false);
})->with(['/privacy', '/terms', '/cookies']);
