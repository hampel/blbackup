<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->web = fakeServer();
    $this->db = fakeServer(['id' => 200, 'name' => 'db1.example.com', 'memory' => 8192, 'vcpus' => 4, 'disk' => 80]);
});

function serverRow(array $server): array
{
    return [
        $server['id'],
        $server['name'],
        Str::padLeft($server['memory'], 6),
        Str::padLeft($server['vcpus'], 5),
        Str::padLeft($server['disk'], 4),
    ];
}

it('lists every server in a table', function () {
    fakeApi([$this->db, $this->web]);

    $this->artisan('servers')
        ->expectsTable(
            ['ID', 'Name', 'Memory', 'VCPUs', 'Disk'],
            // sorted by id, whatever order the API returned them in
            [serverRow($this->web), serverRow($this->db)]
        )
        ->assertSuccessful();
});

it('lists a single server by hostname', function () {
    fakeApi([$this->web]);

    $this->artisan('servers', ['hostname' => 'web1.example.com'])
        ->expectsTable(['ID', 'Name', 'Memory', 'VCPUs', 'Disk'], [serverRow($this->web)])
        ->assertSuccessful();

    Http::assertSent(fn ($request) => str_contains($request->url(), 'hostname=web1.example.com'));
});

it('looks a numeric argument up as a server id', function () {
    fakeApi([$this->web, $this->db]);

    $this->artisan('servers', ['hostname' => '200'])
        ->expectsTable(['ID', 'Name', 'Memory', 'VCPUs', 'Disk'], [serverRow($this->db)])
        ->assertSuccessful();

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/servers/200'));
});

it('lists just the ids with --ids', function () {
    fakeApi([$this->db, $this->web]);

    $this->artisan('servers', ['--ids' => true])
        ->expectsOutput('100')
        ->expectsOutput('200')
        ->doesntExpectOutputToContain('web1.example.com')
        ->assertSuccessful();
});

it('lists just the names with --names', function () {
    fakeApi([$this->web, $this->db]);

    $this->artisan('servers', ['--names' => true])
        ->expectsOutput('db1.example.com')
        ->expectsOutput('web1.example.com')
        ->assertSuccessful();
});

it('fails when the hostname matches no server', function () {
    fakeApi([]);

    $this->artisan('servers', ['hostname' => 'nothing.example.com'])
        ->expectsOutputToContain('No server data returned for nothing.example.com')
        ->assertFailed();
});

it('fails when the account has no servers at all', function () {
    fakeApi([]);

    $this->artisan('servers')
        ->expectsOutputToContain('No server data returned')
        ->assertFailed();
});

it('reports an API error rather than throwing', function () {
    Http::fake(['*' => Http::response(['error' => 'server error'], 500)]);

    $this->artisan('servers')
        ->expectsOutputToContain('Could not fetch server information')
        ->assertFailed();
});
