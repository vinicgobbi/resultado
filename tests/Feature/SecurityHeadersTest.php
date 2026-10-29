<?php

test('pages cannot be framed by other sites nor have their type guessed', function () {
    $this->get('http://localhost/login')
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeader('Content-Security-Policy', "frame-ancestors 'self'")
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('Content-Security-Policy-Report-Only')
        ->assertHeaderMissing('Strict-Transport-Security');
});

test('https is remembered by the browser only when the site is served over it', function () {
    $this->get('https://localhost/login')
        ->assertHeader('Strict-Transport-Security', 'max-age=31536000');
});
