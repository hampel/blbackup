<?php

use Illuminate\Support\Facades\Http;

it('shows the account email and status', function () {
    fakeApi([], account: fakeAccount());

    $this->artisan('account')
        ->expectsTable(['Email', 'Status'], [['backups@example.com', 'active']])
        ->assertSuccessful();
});

it('shows an account that is not active', function () {
    fakeApi([], account: fakeAccount(['status' => 'warning']));

    $this->artisan('account')
        ->expectsTable(['Email', 'Status'], [['backups@example.com', 'warning']])
        ->assertSuccessful();
});

it('fails when the token is rejected', function () {
    Http::fake(['*' => Http::response(['error' => 'unauthorized'], 401)]);

    // BaseCommand turns the API's RequestException into a reported failure
    // rather than a stack trace - this is the only test that covers it
    $this->artisan('account')
        ->expectsOutputToContain('Could not fetch account information [401]')
        ->assertFailed();
});
