<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->web = fakeServer();
    $this->db = fakeServer(['id' => 200, 'name' => 'db1.example.com', 'memory' => 8192, 'vcpus' => 4, 'disk' => 80]);
});

const SERVER_HEADERS = ['ID', 'Name', 'Memory', 'VCPUs', 'Disk'];

function serverRow(array $server): array
{
    return [
        (string) $server['id'],
        $server['name'],
        Str::padLeft($server['memory'], 6),
        Str::padLeft($server['vcpus'], 5),
        Str::padLeft($server['disk'], 4),
    ];
}

it('lists every server in a table', function () {
    fakeApi([$this->db, $this->web]);

    expect(Artisan::call('servers'))->toBe(0);

    // the whole table, so a row the command should not have printed fails here
    expect(renderedTable(Artisan::output()))->toBe([
        SERVER_HEADERS,
        // sorted by id, whatever order the API returned them in
        serverRow($this->web),
        serverRow($this->db),
    ]);
});

it('lists a single server by hostname', function () {
    fakeApi([$this->web]);

    expect(Artisan::call('servers', ['hostname' => 'web1.example.com']))->toBe(0);
    expect(renderedTable(Artisan::output()))->toBe([SERVER_HEADERS, serverRow($this->web)]);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'hostname=web1.example.com'));
});

it('looks a numeric argument up as a server id', function () {
    fakeApi([$this->web, $this->db]);

    expect(Artisan::call('servers', ['hostname' => '200']))->toBe(0);
    expect(renderedTable(Artisan::output()))->toBe([SERVER_HEADERS, serverRow($this->db)]);

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
        ->expectsOutputToContain('HTTP 500')
        ->assertFailed();
});

it('lists the servers on every page, not only the first', function () {
    // BinaryLane pages at twenty. One request used to be all the listing made, so
    // a server on the second page was left out of `servers` and of every --all run
    $page1 = fakeServer(['id' => 100, 'name' => 'web1.example.com']);
    $page2 = fakeServer(['id' => 200, 'name' => 'db1.example.com']);

    Http::fake(function ($request) use ($page1, $page2) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return ($query['page'] ?? '1') === '2'
            ? Http::response(['servers' => [$page2], 'meta' => ['total' => 2]])
            : Http::response([
                'servers' => [$page1],
                'meta' => ['total' => 2],
                'links' => ['pages' => ['next' => 'https://api.binarylane.test/v2/servers?page=2']],
            ]);
    });

    expect(Artisan::call('servers', ['--names' => true]))->toBe(0);

    expect(Artisan::output())->toContain('web1.example.com')->toContain('db1.example.com');
});
