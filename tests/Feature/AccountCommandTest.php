<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

it('shows the account email and status', function () {
    fakeApi([], account: fakeAccount());

    expect(Artisan::call('account'))->toBe(0);
    expect(renderedTable(Artisan::output()))->toBe([
        ['Email', 'Status'],
        ['backups@example.com', 'active'],
    ]);
});

it('shows an account that is not active', function () {
    fakeApi([], account: fakeAccount(['status' => 'warning']));

    expect(Artisan::call('account'))->toBe(0);
    expect(renderedTable(Artisan::output()))->toBe([
        ['Email', 'Status'],
        ['backups@example.com', 'warning'],
    ]);
});

it('fails when the token is rejected', function () {
    // an empty body, which is what BinaryLane really sends with a 401 - and what
    // gives the client's message something to say about the token
    Http::fake(['*' => Http::response('', 401)]);

    // BaseCommand turns the client's exception into a reported failure rather
    // than a stack trace - this is the only test that covers it
    $this->artisan('account')
        // one expectation: both halves are on the same line, and each
        // expectsOutputToContain() consumes a line
        ->expectsOutputToContain('(HTTP 401): the API token was missing, malformed, expired or revoked')
        ->assertFailed();
});
