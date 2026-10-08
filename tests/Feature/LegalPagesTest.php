<?php

it('shows the supplied DPO contact and omits unfinished privacy placeholders', function () {
    $this->get('/privacy')
        ->assertOk()
        ->assertSeeText('We do not sell your personal data.')
        ->assertSeeText('Name: Joshua Llanto')
        ->assertSeeText('Email: llantojoshua73@gmail.com')
        ->assertSeeText('Contact our Data Protection Officer at llantojoshua73@gmail.com.')
        ->assertDontSeeText('Last Updated:')
        ->assertDontSeeText('[EFFECTIVE_DATE]')
        ->assertDontSeeText('[BUSINESS_ADDRESS]')
        ->assertDontSeeText('[DPO_NAME]')
        ->assertDontSeeText('[DPO_EMAIL]')
        ->assertDontSeeText('PART 1:');

    $policy = $this->getJson('/api/legal/privacy-policy')->assertOk()->json('data');

    expect(json_encode($policy))->toContain('Joshua Llanto', 'llantojoshua73@gmail.com')
        ->not->toContain('[EFFECTIVE_DATE]', '[BUSINESS_ADDRESS]', '[DPO_NAME]', '[DPO_EMAIL]', 'PART 1:');
});

it('renders the required terms copy and dispute resolution section', function () {
    $this->get('/terms')
        ->assertOk()
        ->assertSeeText('TERMS AND CONDITIONS (E-Commerce)')
        ->assertSeeText('DTI Online Dispute Resolution (ODR) platform.')
        ->assertDontSeeText('PART 2:');

    $this->getJson('/api/legal/terms')
        ->assertOk()
        ->assertJsonPath('data.sections.0.heading', 'TERMS AND CONDITIONS (E-Commerce)');
});

it('publishes the exact cookie banner copy through the page and API', function () {
    $copy = 'We use cookies to remember your cart, keep you logged in, and understand how you use our site. You can control non-essential cookies through Cookie Preferences on our Cookie Policy page. We do not use cookies to force you to accept marketing.';

    $this->get('/cookies')->assertOk()->assertSeeText($copy)
        ->assertSee("window.dispatchEvent(new Event('btw:open-cookie-preferences'))", false);
    $this->getJson('/api/legal/cookie-policy')
        ->assertOk()
        ->assertJsonPath('data.title', 'Cookies and Tracking')
        ->assertJsonPath('data.body', $copy);
});
