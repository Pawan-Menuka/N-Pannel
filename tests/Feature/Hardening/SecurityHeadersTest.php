<?php

use Illuminate\Support\Facades\Route;

it('adds security headers to web and panel responses', function (string $path) {
    $this->get($path)
        ->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()')
        ->assertHeaderMissing('Strict-Transport-Security');
})->with(['/', '/admin/login']);

it('preserves headers supplied by document responses', function () {
    Route::middleware('web')->get('/header-test', fn () => response('Document')->header('Referrer-Policy', 'no-referrer'));

    $this->get('/header-test')->assertOk()->assertHeader('Referrer-Policy', 'no-referrer');
});

it('only sends HSTS on secure production and staging requests', function (string $environment, string $scheme, bool $expected) {
    $this->app->instance('env', $environment);
    Route::middleware('web')->get('/header-test', fn () => response('OK'));

    $response = $this->get($scheme.'://localhost/header-test');

    $response->assertOk();
    if ($expected) {
        $response->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    } else {
        $response->assertHeaderMissing('Strict-Transport-Security');
    }
})->with([
    ['production', 'https', true], ['staging', 'https', true],
    ['production', 'http', false], ['staging', 'http', false],
    ['local', 'https', false], ['testing', 'https', false], ['preview', 'https', false],
]);

it('recognizes HTTPS at the proxy for HSTS and generated URLs', function (string $environment) {
    $this->app->instance('env', $environment);
    Route::middleware('web')->get('/proxy-header-test', fn () => response()->json([
        'secure' => request()->isSecure(),
        'url' => url('/proxy-target'),
    ]));

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.10'])
        ->withHeaders(['X-Forwarded-Proto' => 'https'])
        ->get('http://localhost/proxy-header-test')
        ->assertOk()
        ->assertHeader('Strict-Transport-Security', 'max-age=31536000')
        ->assertExactJson(['secure' => true, 'url' => 'https://localhost/proxy-target']);
})->with(['staging', 'production']);
