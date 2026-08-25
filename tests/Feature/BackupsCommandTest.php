<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Number;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->server = fakeServer();
    $this->image = fakeImage(['size_gigabytes' => 40.5]);
    $this->older = fakeImage([
        'id' => 111,
        'created_at' => '2026-08-18T14:30:00Z',
        'size_gigabytes' => 39.25,
        'full_name' => 'web1.example.com older backup',
    ]);
});

function imageRow(array $image): array
{
    $created = Carbon\Carbon::createFromFormat("Y-m-d\TH:i:sT", $image['created_at']);

    return [
        Str::padLeft((string) $image['id'], 9),
        $image['full_name'],
        $created->toDateTimeString(),
        $created->timezone('Australia/Sydney')->toDateTimeString(),
        Str::padLeft(Number::format($image['size_gigabytes'], 2), 7),
    ];
}

const BACKUP_HEADERS = ['Backup ID', 'Backup Name', 'Created (UTC)', 'Created (local TZ)', 'Size GB'];

it('lists every backup image when given no server', function () {
    fakeApi([$this->server], [$this->image, $this->older]);

    expect(Artisan::call('backups'))->toBe(0);

    $output = Artisan::output();

    expect($output)->toContain('All backup images on BinaryLane')
        // the whole table, so an image the command should have filtered out
        // fails here rather than passing unnoticed
        ->and(renderedTable($output))->toBe([
            BACKUP_HEADERS,
            imageRow($this->older),
            imageRow($this->image),
        ]);
});

it('shows the creation time in UTC and in the configured timezone', function () {
    config(['binarylane.timezone' => 'America/New_York']);
    fakeApi([$this->server], [$this->image]);

    // both columns come from the one timestamp: 14:30 UTC is 10:30 in New York
    // in August. Asserted as a table row because two expectsOutputToContain()
    // calls cannot both match the same line.
    expect(Artisan::call('backups'))->toBe(0);
    expect(renderedTable(Artisan::output()))->toBe([
        BACKUP_HEADERS,
        [
            Str::padLeft((string) $this->image['id'], 9),
            $this->image['full_name'],
            '2026-08-20 14:30:00',
            '2026-08-20 10:30:00',
            Str::padLeft(Number::format($this->image['size_gigabytes'], 2), 7),
        ],
    ]);
});

it('leaves out public images and anything that is not a backup', function () {
    $public = fakeImage(['id' => 777, 'public' => true, 'full_name' => 'ubuntu-24-04']);
    $snapshot = fakeImage(['id' => 888, 'type' => 'snapshot', 'full_name' => 'a snapshot']);

    fakeApi([$this->server], [$this->image, $public, $snapshot]);

    expect(Artisan::call('backups'))->toBe(0);
    expect(renderedTable(Artisan::output()))->toBe([BACKUP_HEADERS, imageRow($this->image)]);
});

it('lists the backups for a hostname', function () {
    fakeApi([$this->server], [$this->image]);

    expect(Artisan::call('backups', ['server' => 'web1.example.com']))->toBe(0);

    $output = Artisan::output();

    expect($output)->toContain('Backups for web1.example.com (100):')
        ->and(renderedTable($output))->toBe([BACKUP_HEADERS, imageRow($this->image)]);
});

it('looks a numeric argument up as a server id', function () {
    fakeApi([$this->server], [$this->image]);

    $this->artisan('backups', ['server' => '100'])
        ->expectsOutputToContain('Backups for web1.example.com (100):')
        ->assertSuccessful();

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/servers/100'));
});

it('lists just the ids with --ids', function () {
    $public = fakeImage(['id' => 777, 'public' => true]);
    $snapshot = fakeImage(['id' => 888, 'type' => 'snapshot']);

    fakeApi([$this->server], [$this->image, $this->older, $public, $snapshot]);

    $this->artisan('backups', ['--ids' => true])
        ->expectsOutput('111')
        ->expectsOutput('12345')
        // --ids applies the same public / non-backup filter as the table
        ->doesntExpectOutputToContain('777')
        ->doesntExpectOutputToContain('888')
        ->doesntExpectOutputToContain('Backup Name')
        ->assertSuccessful();
});

it('lists just the ids of one server backups with --ids', function () {
    fakeApi([$this->server], [$this->image, $this->older]);

    $this->artisan('backups', ['server' => 'web1.example.com', '--ids' => true])
        ->expectsOutput('111')
        ->expectsOutput('12345')
        ->doesntExpectOutputToContain('web1.example.com older backup')
        ->assertSuccessful();
});

it('lists the download url for each backup with --urls', function () {
    fakeApi(
        [$this->server],
        [$this->image],
        fakeLink(12345, 'https://images.binarylane.com.au/backup-12345.zst')
    );

    $this->artisan('backups', ['--urls' => true])
        ->expectsOutputToContain('Backup download URLs')
        ->expectsOutputToContain('Backup ID: 12345')
        ->expectsOutputToContain('https://images.binarylane.com.au/backup-12345.zst')
        ->assertSuccessful();
});

it('does not fetch download urls unless asked', function () {
    fakeApi([$this->server], [$this->image]);

    $this->artisan('backups')->assertSuccessful();

    Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/download'));
});

it('fails when the account has no images', function () {
    fakeApi([$this->server], []);

    $this->artisan('backups')
        ->expectsOutputToContain('No image data returned')
        ->assertFailed();
});

it('fails when the server has no backups', function () {
    fakeApi([$this->server], []);

    $this->artisan('backups', ['server' => 'web1.example.com'])
        ->expectsOutputToContain('No backup data returned for web1.example.com')
        ->assertFailed();
});

it('fails when the hostname matches no server', function () {
    fakeApi([], [$this->image]);

    $this->artisan('backups', ['server' => 'nothing.example.com'])
        ->expectsOutputToContain('No server data returned for nothing.example.com')
        ->assertFailed();
});
