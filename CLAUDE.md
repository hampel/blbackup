# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

`blbackup` is a BinaryLane VPS backup CLI built on Laravel Zero 12 (PHP 8.2+). It
drives the BinaryLane API to take temporary server snapshots, then shells out to
`wget`, `zstd` and `rclone` to download, verify, ship and expire the resulting
compressed disk images.

`README.md` is upstream Laravel Zero boilerplate and describes the framework, not
this app.

## Commands

```bash
php blbackup                     # default: summary list of all commands
php blbackup app:config          # resolved config (timeouts, binaries, remote, disks, logging)
php blbackup app:test --logs     # write one message at each level, then dump logging config
php blbackup app:test --download=<url>   # exercise the Http download path against a URL

php blbackup account             # BinaryLane account info — cheapest API token check
php blbackup servers [host|id] [--ids|--names]
php blbackup backups [host|id] [--ids] [--urls]

php blbackup create <host|id>|--all [--include=FILE] [--exclude=FILE] [-d|--download] [-m|--move]
php blbackup download <host|id>|--all|--image=ID [-f|--force] [--no-test] [--no-wget] [-m|--move]
php blbackup check <file>|--all [--dry-run]
php blbackup move  <file>|--all [--remote=REMOTE] [--dry-run]
php blbackup clean [--days=N] [--remote] [--dry-run]

php blbackup test                # Laravel Zero's Pest runner (same as vendor/bin/pest)
php vendor/bin/pest tests/Feature/SomeTest.php     # single file
php vendor/bin/pest --filter='some name'           # single test
php blbackup app:build blbackup  # compile a PHAR into builds/ (box.json)
```

`--include` / `--exclude` take a path to a plain-text file, one server hostname
per line, filtered against `$server['name']`. `clean` prompts for confirmation
unless `--dry-run` or `--no-interaction`.

## The pipeline

`create` → `download` → `check` → `move` → `clean` is one chain, and the commands
call each other through `$this->call()` rather than sharing code:

- `create --download` calls `download`, passing `--move` through.
- `download` calls `check` to validate the file (unless `--no-test`) and deletes
  the download if the zstd test fails; then calls `move` if `--move` is set.
- `clean --remote` expires the rclone side as well as the local disk.

So a scheduled full run is `create --all --download --move` followed by
`clean --remote --no-interaction`.

## Architecture

**`App\Api` is the only thing that talks to BinaryLane.** Every method wraps a
`Http::binarylane()` call (a macro registered in `AppServiceProvider` binding the
token and `https://api.binarylane.com.au/v2`) and converts a `RequestException`
into `App\Exceptions\BinaryLaneException`, whose message carries the HTTP status
and reason. `_ide_helper.php` exists solely to declare that macro.

**`BaseCommand::execute()` catches `BinaryLaneException` and `ConnectionException`**
for the whole command, logs and prints them, and returns FAILURE — so command
code calls `$this->api->…` straight, with no try/catch. Subclasses set
`protected string $commandContext`, which is pushed into `Log::withContext()` so
every record from a run is tagged with the command.

**Exit codes are the contract with cron**, and every command that works through
a list uses the same shape: `reject()` rather than `each()`, so one failure
doesn't stop the run and what is left is what failed, then a count logged and
FAILURE returned at the end. `create` reports servers whose backup errored or
timed out; `download` reports the servers whose backup is not in place;
`check`, `move` and `clean` report the files they could not handle. The chain
propagates too: `download` returns the exit code of the `move` it calls, and
`create --download` returns the exit code of the `download`. Copy that shape for
anything new — a `return` inside an `each()` closure returns from the closure,
not the command, which is how `clean`'s remote deletions used to fail silently.

**A skip is not a failure**, and `download` is where that distinction lives.
`downloadImage()` returns whether the backup is *in place afterwards* — true
when it downloaded one and true when one was already there, local or on the
remote; false only when something went wrong. Return false for the
already-downloaded case and a re-run of `download --all` reports trouble every
night, which is the failure mode that makes an exit code worth nothing.

**`log($level, $message, $logMessage = null, $context = [])`** dual-writes: to
Monolog (structured, with `$context`) and to the console (styled, gated by a
level→verbosity map, so `debug` only appears under `-vvv`). Use it rather than
`$this->info()` / `Log::info()` for anything worth recording — the console string
and the log string are deliberately different, the log one being the stable
message with the variable parts moved into context.

`logCmd($description, $cmd)` debug-logs each shell command before it runs; every
external binary invocation goes through it. `getVerbosity()` translates the
command's own `-v`/`-q` into a flag string to splice into the rclone command line.

**Long-running processes use `Process::forever()`** — `wget`, `zstd --test` and
`rclone moveto` all operate on multi-GB files and will exceed any default
timeout. Short probes (`rclone lsjson`, `rclone lsd`) use plain `Process::path()`.

**Progress bars are parsed out of subprocess output.** `Download::processWget()`
scans wget's stderr for its percentage lines and drives a Symfony `ProgressBar`;
`Move::processRclone()` uses `deleteLines()` from `BaseCommand` to redraw
rclone's `--progress` block in place. Both are brittle against output-format
changes in those tools — that is the cost of the live display. Nothing may be
written to the console inside these callbacks except through the progress bar, or
it breaks the redraw (see the `Log::debug` in `Create::backup()` for the pattern).

**Downloads default to `wget`, not the Http client.** `--no-wget` switches to
`Api::download()` (Guzzle sink + progress callback), the original implementation.
`app:test --download` duplicates that Http path inline rather than calling
`Api::download()`, so it can be pointed at an arbitrary URL.

**Filenames encode the source.** Downloads land at
`<download disk>/<server name>/backup-<short name>-<Ymd-His>-<image id>.zst`,
where the datestamp is the image's `created_at` converted to
`binarylane.timezone` and the short name is the first dot-separated label of the
hostname. `move` preserves that relative path under the rclone remote, and both
`download`'s already-exists check and `clean`'s expiry rely on it.

**Completion is judged by size**, comparing the file's bytes-as-GB against the
API's `size_gigabytes` for exact equality. A short file is reported as a probable
incomplete download and left alone; `--force` is what re-downloads it. With
`--move`, the same check runs against `rclone lsjson --stat` before downloading,
so an image already shipped to the remote is not fetched again.

**Two filesystem disks** (`config/filesystems.php`): `local` at `storage_path()`,
and `downloads` at `DOWNLOAD_PATH` (default `storage_path('backups')`). All
backup file handling goes through the `downloads` disk, and shell commands are
built from `Storage::disk('downloads')->path(…)`.

## Configuration and packaging

Everything app-specific is env-driven through `config/binarylane.php` — API
token, download timeout, the three binary paths, `keeponly_days`, the rclone
remote, and the timezone. Read it through `config()`, never `env()` outside
`config/`. `.env.example` documents every variable with its default; `.env`
itself is gitignored, and `app:config` is the way to see what a given install
resolved to.

**Never put an `env()` call in `config/app.php`.** `app:build` evaluates that file
on the build machine and compiles it in as a literal array, so the value freezes
at build time and no `.env` beside the binary can change it. That is why the
timezone lives as `binarylane.timezone` and is applied with
`date_default_timezone_set()` in `AppServiceProvider::boot()` rather than as
`app.timezone`. `bootstrap/app.php` likewise repoints the storage path at
`getcwd()` when running inside a Phar, so a compiled binary resolves `.env`, logs
and the default download path relative to the working directory.

**Logging is off by default** — `logging.default` is `null`. A real install sets
`LOG_CHANNEL`/`LOG_STACK` and `LOG_STORAGE_PATH`; `app:test --logs` is the check
that it took. `ContextLogProcessor` is bound explicitly in `AppServiceProvider`
because Laravel Zero does not register it, and without it `Log::withContext()`
data never reaches the records.

## Testing

`tests/Feature/` covers every command — `create`, `download`, `check`, `move`,
`clean`, and the read-only `servers`, `backups` and `account`. The commands' product is the shell command they assemble, the API call they make and
the file that results, so that is what is asserted: `Process::assertRan()`
against the exact wget/zstd/rclone command string, `Http::assertSent()` against
the request, and the state of the `downloads` disk afterwards.

`tests/Pest.php` pins every binary path, the remote, the timezone and the API
token in a `beforeEach` chained onto `uses()`, so assertions don't depend on the
developer's `.env` — it also forces `logging.default` to `null`, since the
project `.env` is loaded during tests and the suite would otherwise append to
whatever log the developer has configured. `Storage::fake('downloads')` repoints
the download disk into `storage/framework/testing`. Helpers: `fakeServer()` /
`fakeImage()` build API payloads, `fakeApi()` answers every BinaryLane endpoint
by routing on the request path, `fakeBinaries()` fakes the external commands with
a fall-through to success, `wgetWrites()` is a wget fake that writes the file
wget would have written, `writeServerList()` writes an `--include` / `--exclude`
list, `putAgedDownload()` writes a backup with a modification time for `clean`
to expire, `rcloneEntry()` / `rcloneListing()` build `rclone lsjson` output,
`renderedTable()` parses a printed table back into rows for an exact
comparison, and `backupPath()` gives the path the command derives for the
standard fixture. `fakeApi()`'s `$statuses` argument is the queue of action payloads the
`create` poll loop reads, and an entry may be a closure — which is how a test
makes something happen between one poll and the next.

Eight things that will catch you out:

- **`beforeEach()` in `tests/Pest.php` must be chained onto `uses()`** —
  `beforeEach(...)->in('Feature')` on its own parses fine and silently never
  runs, so the tests execute against the developer's real config.
- **`Http::fake()` merges stubs, and the first registered match wins.** A fake
  in `beforeEach` therefore shadows a different one set inside a test. Each test
  here registers its own via `fakeOneBackup()`.
- **`Http::fake()` honours the `sink` option**, so the `--no-wget` path really
  writes the faked body to disk. That is why the fake `.zst` response body is
  exactly `MEGABYTE` bytes.
- **Don't use `expectsTable()` — it cannot see extra rows.** It renders the rows
  you give it and asserts each resulting line appears in the output, so a
  command printing rows the test never mentioned still passes. Deleting
  `backups`' public / non-backup filter passed its table assertion; so did
  injecting a bogus row into all three listing commands. Use
  `renderedTable(Artisan::output())` and compare the whole table with `toBe()`,
  which catches extra rows, missing rows and wrong order alike. The servers
  tests appeared to catch the bogus row only because the name chosen was long
  enough to change the column widths — a shorter one went through.
- **`expectsOutputToContain()` consumes one expectation per line**, so two of
  them cannot both match the same line. Two values on one table row need a
  single `expectsTable()` row instead — which is why the timezone test asserts a
  row rather than two timestamps.
- **`Sleep::fake()` is useless against `create`'s poll loop, and dangerous.**
  A faked sleep returns before the `while` loop runs, so the poll callback never
  executes and `$status` stays null. Sleep is therefore real in
  `CreateCommandTest`, which is only affordable because a poll that ends the loop
  never sleeps — the callback runs before the first sleep. Every test there has
  to reach a stopping condition on its first poll, or it costs ten seconds a
  poll. The timeout test gets there by having the API fake move the clock, since
  the elapsed-time check cannot otherwise trip.
- **`rclone lsjson` emits RFC3339 with nanosecond precision** — and `Z` rather
  than an offset on some backends. `rcloneEntry()` mirrors what the rclone on
  this machine really prints, which is the point: a fixture in a tidier format
  would have passed against a parse that crashes on live output, which is
  exactly the bug these tests found.
- **A download is accepted only if its size in GB exactly equals the API's
  `size_gigabytes`**, so fixture sizes have to be exact in both units. Hence
  `MEGABYTE` / `MEGABYTE_IN_GB` (0.0009765625) rather than a round decimal.

Faking any of this requires the process helpers to be typed against
`Illuminate\Contracts\Process\ProcessResult`, not the concrete
`Illuminate\Process\ProcessResult` — `Process::run()` returns a
`FakeProcessResult` under a fake, which implements the contract but does not
extend the class. Keep new helpers on the contract.

## Conventions

- The code uses Allman braces and its own spacing, which is **not** Laravel/PSR-12.
  Pint is in `require-dev` but there is no `pint.json`, so running it would
  reformat the entire codebase — don't run it across existing files.
- There are no Composer scripts; run Pest directly or via `php blbackup test`.
- `phpunit.xml.dist` names both `tests/Unit` and `tests/Feature` as testsuites,
  and Pest exits 2 if either directory is missing — don't leave one empty.

## Releasing

`config/app.php` has `'version' => app('git.version')`, and `app:build` compiles
that file's evaluated result in as a literal — so **tag first, then build**, or
the binary ships announcing the previous release. Then
`php blbackup app:build blbackup` → `builds/blbackup`, and confirm with
`./builds/blbackup --version`.
